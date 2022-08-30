<?php

declare(strict_types=1);

namespace Erg\SsoUtils;

use App\Exceptions\ExceptionBase;
use App\Models\DepartmentPosition;
use App\Repository\DepartmentPosition\DepartmentPositionRepository;
use App\Utils\Sso\Exceptions\EmployeeIsNotInSubordinatesException;
use App\Utils\Sso\Exceptions\EmployeeIsNotLaborerException;
use App\Utils\Sso\Exceptions\SsoConnectException;
use App\Utils\Sso\Exceptions\UserDoesNotHaveSubordinatesException;
use App\Utils\Sso\Exceptions\UserIsLaborerException;
use App\Utils\Sso\Exceptions\UserNotFoundException;
use App\Utils\Sso\Testing\FakeData;
use Erg\Client\Sso\Auth\Context;
use Erg\Client\Sso\Auth\PersonnelNumber;
use Erg\Client\Sso\Entities\DepartmentWithChildren;
use Erg\Client\Sso\Entities\PersonnelNumber as User;
use Erg\Client\Sso\Entities\PersonnelNumberWithPivot;
use Erg\Client\Sso\Entities\Position;
use Erg\Client\Sso\Entities\PositionWithPivot;
use Erg\Client\Sso\Filters\PositionSearchFilters;
use Erg\Client\Sso\Security\SsoDepartmentTeamPermissions;
use Erg\Client\Sso\Security\SsoPermissions;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\App;
use App\Utils\Sso\Dto\UserDto;
use Erg\Client\Sso\Entities\Department;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\Helpers\Constants;
use Illuminate\Support\Facades\Http as HttpClient;
use Erg\SsoUtils\SsoClient;

/**
 * Class Sso
 * @package App\Utils
 */
class SsoUtils
{

    protected SsoClient $ssoClient;

    protected ?string $userId;

    private ?PersonnelNumber $user = null;

    protected ?array $subordinates = null;

    protected ?DepartmentPositionRepository $departmentPositionRepository = null;


    /**
     * юсеры, которых получили в процессе обработки
     * @var array
     */
    protected array $users = [];

    public function __construct()
    {
        $this->ssoClient = App::get(SsoClient::class);
    }

    public function getAuthToken(): ?string
    {
        return $this->getAuthUser()->authContext->token ?? null;
    }

    public function getFamiliesByIds(array $ids): array
    {
        if (App::environment('testing')) {
            return [];
        }

        try {
            return $this->ssoClient->getFamilies($ids);
        } catch (\Exception $e) {
            Log::error('Exception ', ['family_ids' => $ids, 'message' => $e->getMessage()]);
            throw new SsoConnectException();
        }
    }


    public function getPosition(string $positionId): Position
    {
        if (App::environment('testing')) {
            return FakeData::getPosition($positionId);
        }

        try {
            return $this->ssoClient->getPositionInternal($positionId);
        } catch (\Exception $e) {
            Log::error('Exception ', ['position_id' => $positionId, 'message' => $e->getMessage()]);
            throw new SsoConnectException();
        }
    }

    public function getPositions(array $positionIds): array
    {
        if (App::environment('testing')) {
            return [];
        }

        try {
            $positions = [];

            do {
                $chunkPositionIds = array_splice($positionIds, 0, 500);
                $positions = array_merge($positions, $this->ssoClient->getPositionsInternal($chunkPositionIds));
            } while (count($positionIds));

            return $positions;
        } catch (\Exception $e) {
            Log::error('Exception ', ['position_ids' => $positionIds, 'message' => $e->getMessage()]);
            throw new SsoConnectException();
        }
    }

    public function getGoalMembers(?string $companyId, ?string $departmentId, array $goalWeightIds = []): array
    {
        if (App::environment('testing')) {
            return FakeData::getGoalMembers($goalWeightIds);
        }

        $positionIds = $this->ssoClient->getGoalMembersIds($departmentId, $companyId, $goalWeightIds);
        $positions = $this->ssoClient->getPositionsInternal($positionIds);
        return $positions;
    }

    public function isUserAdmin(): bool
    {
        $user = $this->getAuthUser();
        return in_array(SsoPermissions::SUPER_ADMIN, $user->permissions);
    }

    public function getTopDepartments(bool $flatten): ?DepartmentWithChildren
    {
        return $this->getTopDepartmentsInternal($flatten);
    }

    public function getTopDepartmentsInternal(bool $flatten): ?DepartmentWithChildren
    {
        if (App::environment('testing')) {
            return FakeData::getTopDepartments();
        }
        $url = '/internal/top-departments' . ($flatten ? '?flatten=true' : '');
        $response = $this->sendRequest($url, [], false);

        $topDepartments = $this->decodeArrayOfEntities($response, new DepartmentWithChildren);
        $topDepartment = isset($topDepartments[0]) ? $topDepartments[0] : null;
        if ($topDepartment) {
            $topDepartment = $this->setDepartmentIsBrigade($topDepartment);
            foreach ($topDepartment->children as &$child) {
                $child = $this->setDepartmentIsBrigade($child);
                $child->children = [];
            }
        }

        return $topDepartment;
    }

    public function getUserDto(?string $employeeId, bool $noWorkerOnly = true, bool $workerOnly = false): UserDto
    {
        if (!$employeeId) {
            $user = $this->getAuthUser();
            if ($noWorkerOnly && $this->isUserLaborer($user)) {
                throw new UserIsLaborerException($user->id);
            }
            //не передали $employeeId и юсер  "рабочий"

            $user = $this->getAuthUser();
            $department = $this->getUserDepartment($user);
            return new UserDto($user->id, $department ? $department->id : null, $user, $this->getUserPosition($user));
        }
        //найти юсера в ссо
        $users = $this->getUsersInternal([$employeeId]);
        if (isset($users[0])) {
            $this->users[$users[0]->id] = $users[0];
        } else {
            throw new UserNotFoundException($employeeId);
        }
        $user = $users[0];

        $this->checkEmployeeInTeam($employeeId);

        if ($workerOnly && !$this->isUserLaborer($user)) {
            // employee не "рабочий"
            throw new EmployeeIsNotLaborerException($user->id);
        }

        $userDepartment = $this->getUserDepartment($user);
        return new UserDto($user->id, $userDepartment->id, $user, $this->getUserPosition($user));
    }

    public function areDepartmentsInUserManagement(array $departmentIds): bool
    {
        if ($this->isUserAdmin()) {
            return true;
        }
        $access = true;
        foreach ($departmentIds as $departmentId) {
            if (!$this->isUserDepartmentAdmin($this->getAuthUser(), $departmentId)) {
                //департамент где юсер админ
                $access = false;
                break;
            }
        }
        if ($access) {
            return true;
        }

        $departmentPositionsRepository = $this->getDepartmentPositionRepository();

        $userDepartmentIds = $this->getUserAdminDepartmentIds($this->getAuthUser());

        return $departmentPositionsRepository->areAllDepartmentsChildOf($departmentIds, $userDepartmentIds);
    }

    public function isUserInDepartment(string $departmentId, string $userId): bool
    {
        $departmentPositionsRepository = $this->getDepartmentPositionRepository();

        $position = $departmentPositionsRepository->getPersonnelNumberInDepartment($departmentId, $userId);

        return $position != null;
    }

    // найти все дочерние id департаментов из временной таблицы
    public function getChildDepartmentIds(string $departmentId, ?string $functionalDirectionId = null): array
    {
        if (App::environment('testing')) {
            return array_column(FakeData::departmentsTree($departmentId), 'id');
        }
        $departmentPositionsRepository = $this->getDepartmentPositionRepository();

        return $departmentPositionsRepository->getChildDepartmentIds($departmentId, $functionalDirectionId);
    }

    public function getDepartmentPositionsCount(
        array $departmentIds,
        bool $withPersonnelNumberOnly = true,
        bool $withDepth = false
    ): array {
        if (App::environment('testing')) {
            return [];
        }

        $departmentPositionsRepository = $this->getDepartmentPositionRepository();

        if (!$withDepth) {
            return $departmentPositionsRepository
                ->getDepartmentPositionsCounts($departmentIds, $withPersonnelNumberOnly)
                ->keyBy('department_id')
                ->all();
        }
        $out = [];
        foreach ($departmentIds as $departmentId) {
            $departmentPosition = new DepartmentPosition();
            $departmentPosition->department_id = $departmentId;
            $departmentPosition->{'count'} = $departmentPositionsRepository
                ->getDepartmentPositionsCountWithDepth($departmentId, $withPersonnelNumberOnly);
            $out[$departmentId] = $departmentPosition;
        }

        return $out;
    }


    /*
    * Является ли юсер админом в департаменте
    */
    public function isUserDepartmentAdmin(?PersonnelNumber $user, ?string $departmentId): bool
    {
        if (!$user || !$departmentId) {
            return false;
        }

        return isset($user->department_permissions[$departmentId]) &&
            in_array(SsoDepartmentTeamPermissions::ADMIN, $user->department_permissions[$departmentId]);
    }

    public function getUserAdminDepartmentIds(PersonnelNumber $user): array
    {
        $ids = [];
        if (!empty($user->department_permissions)) {
            foreach ($user->department_permissions as $id => $permissions) {
                if (in_array(SsoDepartmentTeamPermissions::ADMIN, $permissions)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    public function getProfessions(array $professionIds): array
    {
        $url = '/internal/professions';

        return $this->sendRequest($url, ['ids' => $professionIds]);
    }

    public function setDepartmentIsBrigade(Department $department): Department
    {
        if (!property_exists($department, 'is_brigade')) {
            $departmentName = mb_strtoupper($department->name);

            $department->{'is_brigade'} =
                mb_strpos($departmentName, 'БРИГАДА') !== false ||
                mb_strpos($departmentName, 'УЧАСТОК') !== false;
        }

        return $department;
    }

    public function revertPosition(Position $position): ?PersonnelNumberWithPivot
    {
        $personnelNumber = $this->getPositionPersonnelNumber($position);
        if (!$personnelNumber) {
            return null;
        }
        $position->personnel_numbers = [];
        $personnelNumber->positions = [$position];

        return $personnelNumber;
    }

    public function revertPersonnelNumber(PersonnelNumber $personnelNumber): ?Position
    {
        $position = $this->getUserPosition($personnelNumber);

        if (!$position) {
            return null;
        }

        $personnelNumber->positions = [];
        $personnelNumber->authContext = new Context('', null, null, null);
        $personnelNumber->impersonator = null;
        $position->personnel_numbers = [$personnelNumber];

        return $position;
    }

    public function checkEmployeeInTeam(?string $employeeId): bool
    {
        // проверка, что в команде
        $subordinates = $this->getDirectSubordinates();

        if (empty($subordinates)) {
            throw new UserDoesNotHaveSubordinatesException();
        }

        if (!$this->isUserInSubordinates($subordinates, $employeeId)) {
            throw new EmployeeIsNotInSubordinatesException($employeeId);
        }
        return true;
    }

    /**
     * получить департамент, где он начальник
     */
    public function getUserManagedDepartment(PersonnelNumber $user): ?Department
    {
        $position = $this->getUserPosition($user);
        $department = $position ? $position->department : null;

        return $position && $department && $department->manager_id == $position->id ? $department : null;
    }

    public function getUserDepartment(?User $user, bool $withManager = false): ?Department
    {
        if (!$user) {
            return null;
        }

        $position = $this->getUserPosition($user);

        $department = $position ? $this->setDepartmentIsBrigade($position->department) : null;
        if (!$withManager || !$department || !$department->manager_id) {
            return $department;
        }

        $managers = $this->getPositions([$department->manager_id]);
        $department->{'manager'} = isset($managers[0]) ? $managers[0] : null;

        return $department;
    }

    public function getChildDepartments(string $departmentId): array
    {
        if (App::environment('testing')) {
            return FakeData::departmentsTree($departmentId);
        }
        try {
            $departments = $this->ssoClient->getDepartmentsInternal([], $departmentId);
            $out = [];
            foreach ($departments as $department) {
                $out[] = $this->setDepartmentIsBrigade($department);
            }

            return $out;
        } catch (\Exception $e) {
            Log::error('Exception ', ['department_id' => $departmentId, 'message' => $e->getMessage()]);
            throw new SsoConnectException();
        }
    }

    public function getDepartmentsInternal(array $departmentIds, bool $indexed = false, ?array $fields = null): array
    {
        if (App::environment('testing')) {
            return FakeData::getDepartments([Constants::USER_DEPARTMENT, Constants::USER_DEPARTMENT_MAIN], $indexed);
        }
        if (empty($departmentIds)) {
            return [];
        }
        // в документации нет, но похоже, больше не отдаёт
        $chunkSize = 500;

        try {
            $out = [];
            while (!empty($departmentIds)) {
                $chunk = array_splice($departmentIds, 0, $chunkSize);

                $departments = $this->ssoClient->getDepartmentsInternal($chunk);
                foreach ($departments as $department) {
                    if (!$fields) {
                        $outDepartment = $this->setDepartmentIsBrigade($department);
                    } else {
                        // как массив с заданными полями
                        $outDepartment = $this->getDepartmentАsArray($department, $fields);
                    }
                    if ($indexed) {
                        $out[is_array($outDepartment) ? $outDepartment['id'] : $outDepartment->id] = $outDepartment;
                    } else {
                        $out[] = $outDepartment;
                    }
                }
            }

            return $out;
        } catch (\Exception $e) {
            Log::error('Exception ', ['department_ids' => $departmentIds, 'message' => $e->getMessage()]);
            throw new SsoConnectException();
        }
    }

    protected function getDepartmentАsArray(Department $department, array $fields): array
    {
        $out = ['id' => $department->id];

        foreach ($fields as $field) {
            $fieldSet = explode('.', $field);

            $value = $department;
            foreach ($fieldSet as $key) {
                $value = $value->$key;
                if ($value === null) {
                    break;
                }
            }
            $out[$field] = $value;
        }

        return $out;
    }

    public function getTeam(): array
    {
        $subordinates = $this->getDirectSubordinates(false);
        if (empty($subordinates)) {
            throw new UserDoesNotHaveSubordinatesException();
        }

        $team = [];
        $position = $this->getUserPosition($this->getAuthUser());
        $this->transformTeam($team, $subordinates, $position, true);
        return $team;
    }

    private function transformTeam(&$team, $subordinates, Position $globalPosition, bool $isMyTeam = false)
    {
        foreach ($subordinates as $position) {
            $hasSubordinates = false;
            $personnelNumbers = $this->getPositionsPersonnelNumbers([$position]);
            foreach ($personnelNumbers as $personnelNumber) {
                $copyPosition = clone $position;
                $copyPosition->global_position_id = $globalPosition->id;
                $copyPosition->global_position_name = $globalPosition->name;
                $copyPosition->is_my_team = $isMyTeam;

                $copyPosition->personnel_numbers = [];
                $user = clone $personnelNumber;
                $copyPosition->personnel_numbers [] = $user;
                if (!$copyPosition instanceof PositionWithPivot) {
                    $copyPosition = PositionWithPivot::fromPosition($copyPosition, $personnelNumber);
                }

                $personnelNumber->positions = [$copyPosition];
                $team[] = $personnelNumber;
                $hasSubordinates = true;
            }

            if (!$hasSubordinates) {
                $subordinates = $this->getSubordinatesByPositionId($position->id, false);
                $this->transformTeam($team, $subordinates, $position);
            }
        }
    }

    public function getAuthUser(): ?PersonnelNumber
    {

        if ($this->user == null) {
            $this->user = Auth::user();
            if ($this->user) {
                $this->users[$this->user->id] = $this->user;
            }
        }
        return $this->user;
    }

    public function getUserById(?string $employeeId): User
    {
        $users = $this->getUsersInternal([$employeeId]);
        if (isset($users[0])) {
            $this->users[$users[0]->id] = $users[0];
        } else {
            throw new UserNotFoundException($employeeId);
        }
        $user = $users[0];

        return $user;
    }

    public function getUsersByIds(array $userIds): array
    {
        $out = [];

        $userIds = array_unique(array_diff($userIds, [null]));
        if (empty($userIds)) {
            return $out;
        }

        foreach ($this->getUsersInternal($userIds) as $user) {
            $out[$user->id] = $user;
            $this->users[$user->id] = $user;
        }
        return $out;
    }

    public function isPositionInSubordinates(?Position $position): bool
    {
        if (!$position) {
            return false;
        }
        if ($this->isUserAdmin() || $this->isUserDepartmentAdmin($this->getAuthUser(), $position->department_id)) {
            return true;
        }

        foreach ($this->getDirectSubordinates() as $subPosition) {
            if ($subPosition->id == $position->id) {
                return true;
            }
        }

        return false;
    }

    public function getDepartmentById(string $id): ?Department
    {
        if (App::environment('testing')) {
            $departments = FakeData::getDepartments([$id]);
            return isset($departments[0]) ? $departments[0] : null;
        }

        try {
            $department = $this->ssoClient->getDepartmentInternal($id);
            return $department ? $this->setDepartmentIsBrigade($department) : null;
        } catch (\Erg\Client\Sso\Exceptions\DepartmentNotFoundException $e) {
            return null;
        }
    }

    public function getDepartmentsWithCompany(array $ids): array
    {
        if (App::environment('testing')) {
            $departments = FakeData::getDepartments($ids);
            return $departments;
        }
        $data = ['ids' => $ids, 'with_company' => true];
        $url = '/internal/departments';

        $response = $this->sendRequest($url, $data);

        return $this->decodeArrayOfEntities($response, new Department());
    }

    // todo временно пока непонятно кому писать письма
    public function getHrId(): string
    {
        return Constants::HR_ID;
    }

    /**
     * получить запомненных юсеров
     * @return array
     */
    public function getUsersList(): array
    {
        return $this->users;
    }

    public function getUserPosition(User $user, bool $withEmptyPercent = false): ?Position
    {
        if (empty($user->positions)) {
            return null;
        }
        foreach ($user->positions as $position) {
            $percent = $position->employment_percent ?? 0;
            if (empty($position->is_acting) && ($percent > 0 || $withEmptyPercent || is_null($position->employment_percent))) {
                return $position;
            }
        }

        return null;
    }

    public function getPositionAttributes(array $positionIds): array
    {
        if (App::environment('testing')) {
            return [];
        }
        if (empty($positionIds)) {
            return [];
        }

        $url = '/internal/position-attrs';

        return $this->sendRequest($url, ['ids' => $positionIds]);
    }

    public function getDepartmentPositions(array $departmentIds): ?array
    {
        if (App::environment('testing')) {
            return FakeData::getDepartmentPositions();
        }
        if (empty($departmentIds)) {
            return null;
        }

        return $this->ssoClient->getPositionsInternalAdvanced(new PositionSearchFilters([], $departmentIds));
    }


    public function isUserLaborer(?User $user): bool
    {
        $position = $this->getUserPosition($user);

        return $position ? $this->isPositionLaborer($position) : false;
    }

    public function isPositionLaborer(?Position $position): bool
    {
        return $position && property_exists($position, 'is_worker') ? $position->is_worker : false;
    }

    public function isUserRss(?User $user): bool
    {
        $position = $this->getUserPosition($user);

        return $position ? $this->isPositionRss($position) : false;
    }

    public function isPositionRss(?Position $position): bool
    {
        return $position && property_exists($position, 'is_mse') ? $position->is_mse : false;
    }

    public function hasPositionSubordinates(Position $position): bool
    {
        return $position && property_exists($position,
            'subordinates_count') ? $position->subordinates_count > 0 : false;
    }

    public function isUserInSubordinates(array $subordinates, string $employeeId): bool
    {
        if (empty($subordinates)) {
            return false;
        }

        foreach ($subordinates as $position) {
            $personnelNumbers = $this->getPositionsPersonnelNumbers([$position]);
            foreach ($personnelNumbers as $personnelNumber) {
                if ($personnelNumber && $personnelNumber->id == $employeeId) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getPositionPersonnelNumber(
        Position $position,
        bool $withEmptyPercent = false
    ): ?PersonnelNumberWithPivot {
        if (empty($position->personnel_numbers)) {
            return null;
        }

        foreach ($position->personnel_numbers as $personnelNumber) {
            if ($this->isActivePersonnelNumber($personnelNumber, $withEmptyPercent)) {
                return $personnelNumber;
            }
        }

        return null;
    }

    protected function getUsersInternal(array $userIds): array
    {
        if (App::environment('testing')) {
            return FakeData::getUsers($userIds);
        }
        $requestCount = 1000;
        try {
            $users = [];

            do {
                $chunkUserIds = array_splice($userIds, 0, $requestCount);
                $users = array_merge($users, $this->ssoClient->getPersonnelNumbersInternal($chunkUserIds));
            } while (count($userIds));

            return $users;
        } catch (\Exception $e) {
            Log::error('Exception ', ['user_ids' => $userIds, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getSubordinatesByPositionId(string $positionId, ?bool $withEmployeesWithoutManager = true): array
    {
        if (App::environment('testing')) {
            return [];
        }

        $positions = [];
        $subordinates = $this->getDirectSubordinatesByPositionId($positionId);
        if ($withEmployeesWithoutManager) {
            $this->uploadEmployeesWithoutManager($positions, $subordinates);
        } else {
            $positions = $subordinates;
        }

        return $positions;
    }

    private function getDirectSubordinatesByPositionId(string $positionId): array
    {
        try {
            return $this->ssoClient->getDirectSubordinatesInternal($positionId, false);
        } catch (\Exception $e) {
            Log::error('Exception ', ['position_id' => $positionId, 'message' => $e->getMessage()]);
            throw new SsoConnectException();
        }
    }

    private function uploadEmployeesWithoutManager(array &$positions, array $subordinates)
    {
        foreach ($subordinates as $position) {
            $personnelNumber = $this->getPositionPersonnelNumber($position);
            if (!$personnelNumber) {
                $subordinates = $this->getDirectSubordinatesByPositionId($position->id);
                $subordinates = array_filter($subordinates, function ($findPosition) use ($position) {
                    return $findPosition->id !== $position->id;
                });
                $this->uploadEmployeesWithoutManager($positions, $subordinates);
                continue;
            }

            $positions[] = $position;
        }
    }

    public function getPositionsPersonnelNumbers(array $positions): array
    {
        $out = [];
        foreach ($positions as $position) {
            foreach ($position->personnel_numbers as $personnelNumber) {
                if ($this->isActivePersonnelNumber($personnelNumber)) {
                    $out[] = $personnelNumber;
                }
            }
        }

        return $out;
    }

    private function isActivePersonnelNumber(User $personnelNumber, bool $withEmptyPercent = false): bool
    {
        $percent = $personnelNumber->employment_percent ?? 0;
        return empty($personnelNumber->is_acting) && ($percent > 0 || $withEmptyPercent || is_null($personnelNumber->employment_percent));
    }

    public function getDirectSubordinates(?bool $withEmployeesWithoutManager = true): ?array
    {
        if ($this->subordinates === null) {
            $subordinates = $this->getSubordinates($withEmployeesWithoutManager);
            $this->subordinates = $subordinates ? $subordinates : [];
        }

        return $this->subordinates;
    }

    protected function getSubordinates(?bool $withEmployeesWithoutManager = true): ?array
    {
        $user = $this->getAuthUser();
        if (App::environment('testing')) {
            return FakeData::getSubordinates($user->id);
        }

        $position = $this->getUserPosition($user);
        if (!$position) {
            throw new ExceptionBase('User does not have position');
        }

        return $this->getSubordinatesByPositionId($position->id, $withEmployeesWithoutManager);
    }

    /*
     * запрашиваем то, что нет в клиенте
     */
    protected function sendRequest(string $url, ?array $data = [], bool $isPost = true): array
    {
        $service = Config::get('services.sso');

        $request = HttpClient::withBasicAuth($service['internal_api_login'], $service['internal_api_password'])
            ->timeout((int)$service['connect_timeout']);

        if (App::environment('local')) {
            $request->withoutVerifying();
        }

        try {
            if ($isPost) {
                $response = $request->post($service['url'] . $url, $data);
            } else {
                $response = $request->get($service['url'] . $url, $data);
            }
        } catch (ConnectException $e) {
            throw new $e;
        }

        return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    }

    /*
     * как сделано в клиенте, для наших запросов без клиента
     */
    protected function decodeArrayOfEntities(array $body, object $entity): array
    {

        $result = [];
        if (!empty($body['data'])) {
            $mapper = new \JsonMapper();
            $mapper->bEnforceMapType = false;

            foreach ($body['data'] as $datum) {
                $result[] = $mapper->map($datum, clone $entity);
            }
        }

        return $result;
    }

    protected function getDepartmentPositionRepository(): DepartmentPositionRepository
    {
        if ($this->departmentPositionRepository === null) {
            $this->departmentPositionRepository = new DepartmentPositionRepository();
        }

        return $this->departmentPositionRepository;
    }

}
