<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\VersionConflictException;
use App\Repository\TrainingRepository;
use InvalidArgumentException;

final class ApiController
{
    public function __construct(private readonly TrainingRepository $training = new TrainingRepository()) {}

    private function input(bool $requireActionId = false): array
    {
        $data = \json_body();
        Csrf::requireValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['_csrf'] ?? null));
        if ($requireActionId && (!is_string($data['client_action_id'] ?? null) || !preg_match('/^[a-zA-Z0-9._:-]{8,80}$/', $data['client_action_id']))) {
            throw new InvalidArgumentException('Для изменяющего действия нужен корректный client_action_id.');
        }
        return $data;
    }

    public function start(): never
    {
        $user = Auth::requireUser(true);
        try {
            $data = $this->input(true);
            if (!is_int($data['plan_id'] ?? null) || $data['plan_id'] < 1 || !is_array($data['readiness'] ?? null)) {
                throw new InvalidArgumentException('Проверьте идентификатор плана и readiness.');
            }
            $readiness = $data['readiness'];
            $readiness['client_action_id'] = $data['client_action_id'];
            $id = $this->training->startSession($data['plan_id'], (int) $user['id'], $readiness);
            \json_response(['session_id' => $id, 'redirect' => url('/sessions/' . $id)], 201);
        } catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function session(string $id): never
    {
        $user = Auth::requireUser(true);
        $session = $this->training->session((int) $id, (int) $user['id']);
        $session ? \json_response(['data' => $session]) : \json_response(['error' => 'Не найдено.'], 404);
    }

    public function addSet(string $id): never
    {
        $user = Auth::requireUser(true);
        try { \json_response(['data' => $this->training->addSet((int) $id, (int) $user['id'], $this->input(true))], 201); }
        catch (VersionConflictException $e) { \json_response(['error' => $e->getMessage(), 'conflict' => true], 409); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function updateSet(string $id): never
    {
        $user = Auth::requireUser(true);
        try { \json_response(['data' => $this->training->updateSet((int) $id, (int) $user['id'], $this->input(true))]); }
        catch (VersionConflictException $e) { \json_response(['error' => $e->getMessage(), 'conflict' => true], 409); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function exerciseStatus(string $id): never
    {
        $user = Auth::requireUser(true);
        try { \json_response(['data' => $this->training->setExerciseStatus((int) $id, (int) $user['id'], $this->input(true))]); }
        catch (VersionConflictException $e) { \json_response(['error' => $e->getMessage(), 'conflict' => true], 409); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function replaceExercise(string $id): never
    {
        $user = Auth::requireUser(true);
        try { \json_response(['data' => $this->training->replaceExercise((int) $id, (int) $user['id'], $this->input(true))]); }
        catch (VersionConflictException $e) { \json_response(['error' => $e->getMessage(), 'conflict' => true], 409); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function discomfort(string $id): never
    {
        $user = Auth::requireUser(true);
        try { \json_response(['data' => $this->training->logDiscomfort((int) $id, (int) $user['id'], $this->input(true))], 201); }
        catch (VersionConflictException $e) { \json_response(['error' => $e->getMessage(), 'conflict' => true], 409); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function finish(string $id): never
    {
        $user = Auth::requireUser(true);
        try { $session = $this->training->finish((int) $id, (int) $user['id'], $this->input(true)); \json_response(['data' => $session, 'redirect' => url('/sessions/' . $id)]); }
        catch (VersionConflictException $e) { \json_response(['error' => $e->getMessage(), 'conflict' => true], 409); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function weekly(): never
    {
        $user = Auth::requireUser(true);
        \json_response(['data' => $this->training->weeklyAnalytics((int) $user['id'], (string) $user['timezone'])]);
    }

    public function createSwimming(): never
    {
        $user = Auth::requireUser(true);
        try { \json_response(['data' => $this->training->createSwimming((int) $user['id'], $this->input(true))], 201); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }

    public function swimming(string $id): never
    {
        $user = Auth::requireUser(true);
        $session = $this->training->swimmingSession((int) $id, (int) $user['id']);
        $session ? \json_response(['data' => $session]) : \json_response(['error' => 'Не найдено.'], 404);
    }

    public function updateSwimming(string $id): never
    {
        $user = Auth::requireUser(true);
        try { \json_response(['data' => $this->training->updateSwimming((int) $id, (int) $user['id'], $this->input(true))]); }
        catch (VersionConflictException $e) { \json_response(['error' => $e->getMessage(), 'conflict' => true], 409); }
        catch (InvalidArgumentException $e) { \json_response(['error' => $e->getMessage()], 422); }
    }
}
