<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Repository\TrainingRepository;
use App\Service\BackupService;
use App\Service\PlanImportService;
use App\Service\ReportService;
use App\Service\SwimmingReportService;
use InvalidArgumentException;

final class WebController
{
    public function __construct(private readonly TrainingRepository $training = new TrainingRepository()) {}

    public function home(): void
    {
        if (Auth::user()) {
            $this->dashboard();
            return;
        }

        \render('landing', [
            'landingPage' => true,
            'metaDescription' => 'Ритм помогает записывать тренировки, передавать фактические данные ChatGPT или другому ИИ и получать персональный план с учётом прогресса.',
        ], 'Дневник тренировок с ИИ-тренером');
    }

    public function dashboard(): void
    {
        $user = Auth::requireUser();
        \render('dashboard', ['user' => $user, ...$this->training->dashboard((int) $user['id'])], 'Сегодня');
    }

    public function help(): void
    {
        Auth::requireUser();
        \render('help', [], 'Как пользоваться');
    }

    public function importForm(): void
    {
        Auth::requireUser();
        \render('plans/import', ['preview' => $_SESSION['import_preview'] ?? null, 'error' => $_SESSION['flash_error'] ?? null], 'Импорт плана');
        unset($_SESSION['flash_error']);
    }

    public function importPreview(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/plans/import');
        }
        try {
            $file = $_FILES['plan'] ?? null;
            $limit = max(1, (int) \env('MAX_UPLOAD_BYTES', '1048576'));
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Не удалось принять файл. Выберите JSON и повторите попытку.');
            }
            if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Источник загруженного файла не подтверждён.');
            }
            if (!is_string($file['name'] ?? null) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'json') {
                throw new InvalidArgumentException('Поддерживаются только файлы с расширением .json.');
            }
            if (!is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > $limit) {
                throw new InvalidArgumentException('JSON-файл пуст или превышает установленный лимит.');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($mime, ['application/json', 'text/plain', 'application/octet-stream'], true)) {
                throw new InvalidArgumentException('Содержимое файла не распознано как JSON.');
            }
            $json = file_get_contents($file['tmp_name']);
            $service = new PlanImportService();
            $data = $service->decode((string) $json);
            $_SESSION['import_data'] = $data;
            $_SESSION['import_preview'] = $service->preview($data, (int) $user['id']);
        } catch (\Throwable $exception) {
            unset($_SESSION['import_data'], $_SESSION['import_preview']);
            $_SESSION['flash_error'] = $exception->getMessage();
        }
        \redirect('/plans/import');
    }

    public function importCancel(): never
    {
        Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/plans/import');
        }
        unset($_SESSION['import_data'], $_SESSION['import_preview']);
        \redirect('/plans/import');
    }

    public function importConfirm(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null) || !is_array($_SESSION['import_data'] ?? null)) {
            $_SESSION['flash_error'] = 'Превью устарело. Загрузите файл ещё раз.';
            \redirect('/plans/import');
        }
        try {
            $planId = (new PlanImportService())->import($_SESSION['import_data'], (int) $user['id'], !empty($_POST['create_unknown']));
            unset($_SESSION['import_data'], $_SESSION['import_preview']);
            \redirect('/plans/' . $planId);
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
            \redirect('/plans/import');
        }
    }

    public function plan(string $id): void
    {
        $user = Auth::requireUser();
        $plan = $this->training->plan((int) $id, (int) $user['id']);
        if (!$plan) {
            http_response_code(404);
            \render('not-found', [], 'План не найден');
            return;
        }
        \render('plans/show', [
            'plan' => $plan,
            'error' => $_SESSION['flash_error'] ?? null,
            'success' => $_SESSION['flash_success'] ?? null,
        ], $plan['name']);
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
    }

    public function reschedulePlan(string $id): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла. Обновите страницу.';
            \redirect('/plans/' . $id);
        }
        try {
            $this->training->reschedulePlan(
                (int) $id,
                (int) $user['id'],
                (string) ($_POST['scheduled_date'] ?? ''),
                (int) ($_POST['version'] ?? 0),
            );
            $_SESSION['flash_success'] = 'Дата тренировки изменена. План и его история сохранены.';
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
        }
        \redirect('/plans/' . $id);
    }

    public function deletePlan(string $id): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла. Обновите страницу.';
            \redirect('/plans/' . $id);
        }
        try {
            $this->training->softDeletePlan(
                (int) $id,
                (int) $user['id'],
                (int) ($_POST['version'] ?? 0),
                !empty($_POST['confirm_delete']),
            );
            $_SESSION['flash_success'] = 'План мягко удалён; запись сохранена в истории изменений.';
            \redirect('/');
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
            \redirect('/plans/' . $id);
        }
    }

    public function comingSoon(): void
    {
        Auth::requireUser();
        \render('coming-soon', [], 'Скоро в Ритме');
    }

    public function session(string $id): void
    {
        $user = Auth::requireUser();
        $session = $this->training->session((int) $id, (int) $user['id']);
        if (!$session) {
            http_response_code(404);
            \render('not-found', [], 'Тренировка не найдена');
            return;
        }
        if ($session['status'] === 'completed') {
            \render('sessions/summary', [
                'session' => $session,
                'report' => (new ReportService())->build((int) $id, (int) $user['id']),
                'error' => $_SESSION['flash_error'] ?? null,
                'success' => $_SESSION['flash_success'] ?? null,
            ], 'Итоги тренировки');
            unset($_SESSION['flash_error'], $_SESSION['flash_success']);
            return;
        }
        \render('sessions/workout', ['session' => $session], $session['name']);
    }

    public function history(): void
    {
        $user = Auth::requireUser();
        \render('history', [
            'history' => $this->training->history((int) $user['id'], max(1, (int) ($_GET['page'] ?? 1)), $_GET, (string) $user['timezone']),
            'timezone' => (string) $user['timezone'],
        ], 'История');
    }

    public function analytics(): void
    {
        $user = Auth::requireUser();
        \render('analytics', ['analytics' => $this->training->weeklyAnalytics((int) $user['id'], (string) $user['timezone'])], 'Аналитика');
    }

    public function exerciseAnalytics(string $id): void
    {
        $user = Auth::requireUser();
        $analytics = $this->training->exerciseAnalytics($id, (int) $user['id'], (string) $user['timezone']);
        if (!$analytics) {
            http_response_code(404);
            \render('not-found', [], 'Упражнение не найдено');
            return;
        }
        \render('exercise-analytics', ['analytics' => $analytics, 'timezone' => (string) $user['timezone']], (string) $analytics['exercise']['name']);
    }

    public function programs(): void
    {
        $user = Auth::requireUser();
        \render('programs', ['versions' => $this->training->programs((int) $user['id'])], 'История программы');
    }

    public function exercises(): void
    {
        $user = Auth::requireUser();
        \render('exercises', [
            'exercises' => $this->training->exercises((int) $user['id']),
            'error' => $_SESSION['flash_error'] ?? null,
            'success' => $_SESSION['flash_success'] ?? null,
        ], 'Справочник упражнений');
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
    }

    public function addExercise(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/exercises');
        }
        try {
            $this->training->addExercise((int) $user['id'], $_POST);
            $_SESSION['flash_success'] = 'Упражнение добавлено. exercise_id останется неизменным.';
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
        }
        \redirect('/exercises');
    }

    public function updateExercise(string $id): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/exercises');
        }
        try {
            $this->training->updateExercise((int) $user['id'], $id, $_POST);
            $_SESSION['flash_success'] = 'Настройки упражнения сохранены.';
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
        }
        \redirect('/exercises');
    }

    public function measurements(): void
    {
        $user = Auth::requireUser();
        $items = $this->training->measurements((int) $user['id']);
        \render('measurements', ['items' => $items, 'charts' => $this->training->measurementCharts($items), 'error' => $_SESSION['flash_error'] ?? null], 'Прогресс тела');
        unset($_SESSION['flash_error']);
    }

    public function addMeasurement(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) \redirect('/measurements');
        try { $this->training->addMeasurement((int) $user['id'], $_POST); } catch (\Throwable $e) { $_SESSION['flash_error'] = $e->getMessage(); }
        \redirect('/measurements');
    }

    public function swimming(): void
    {
        $user = Auth::requireUser();
        \render('swimming', [
            'items' => $this->training->swimming((int) $user['id']),
            'schedule' => array_values(array_filter($this->training->schedule((int) $user['id']), static fn (array $row): bool => $row['workout_type'] === 'swimming' && (int) $row['active'] === 1)),
            'user' => $user,
            'error' => $_SESSION['flash_error'] ?? null,
            'success' => $_SESSION['flash_success'] ?? null,
        ], 'Плавание');
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
    }

    public function addSwimming(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) \redirect('/swimming');
        try {
            $result = $this->training->createSwimming((int) $user['id'], $_POST);
            \redirect('/swimming/' . $result['id']);
        } catch (\Throwable $e) { $_SESSION['flash_error'] = $e->getMessage(); }
        \redirect('/swimming');
    }

    public function swimmingSession(string $id): void
    {
        $user = Auth::requireUser();
        $session = $this->training->swimmingSession((int) $id, (int) $user['id']);
        if (!$session) { http_response_code(404); \render('not-found', [], 'Плавание не найдено'); return; }
        \render('swimming-show', [
            'session' => $session,
            'schedule' => array_values(array_filter($this->training->schedule((int) $user['id']), static fn (array $row): bool => $row['workout_type'] === 'swimming' && (int) $row['active'] === 1)),
            'sequence' => $this->training->trainingSequence((int) $user['id']),
            'user' => $user,
            'error' => $_SESSION['flash_error'] ?? null,
            'success' => $_SESSION['flash_success'] ?? null,
        ], 'Плавание ' . $session['swim_date']);
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
    }

    public function editSwimming(string $id): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) { $_SESSION['flash_error'] = 'Сессия формы истекла.'; \redirect('/swimming/' . $id); }
        try {
            $this->training->updateSwimming((int) $id, (int) $user['id'], $_POST);
            $_SESSION['flash_success'] = 'Запись обновлена; изменение добавлено в audit log.';
        } catch (\Throwable $e) { $_SESSION['flash_error'] = $e->getMessage(); }
        \redirect('/swimming/' . $id);
    }

    public function schedule(): void
    {
        $user = Auth::requireUser();
        \render('schedule', ['items' => $this->training->schedule((int) $user['id']), 'error' => $_SESSION['flash_error'] ?? null, 'success' => $_SESSION['flash_success'] ?? null], 'Расписание');
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
    }

    public function saveSchedule(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) { $_SESSION['flash_error'] = 'Сессия формы истекла.'; \redirect('/schedule'); }
        try { $this->training->saveSchedule((int) $user['id'], $_POST); $_SESSION['flash_success'] = 'Расписание сохранено.'; }
        catch (\Throwable $e) { $_SESSION['flash_error'] = $e->getMessage(); }
        \redirect('/schedule');
    }

    public function exportSwimming(string $id, string $format): never
    {
        $user = Auth::requireUser();
        $service = new SwimmingReportService($this->training);
        $report = $service->build((int) $id, (int) $user['id']);
        $base = preg_replace('/[^a-zA-Z0-9_-]/', '-', $report['session']['session_id']);
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$base}.json\"");
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            exit;
        }
        if ($format === 'md') {
            header('Content-Type: text/markdown; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$base}.md\"");
            echo $service->markdown($report);
            exit;
        }
        \json_response(['error' => 'Формат не поддерживается.'], 400);
    }

    public function export(string $id, string $format): never
    {
        $user = Auth::requireUser();
        $reporter = new ReportService();
        $report = $reporter->build((int) $id, (int) $user['id']);
        $base = preg_replace('/[^a-zA-Z0-9_-]/', '-', $report['session']['session_id']);
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$base}.json\"");
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            exit;
        }
        if ($format === 'md') {
            header('Content-Type: text/markdown; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$base}.md\"");
            echo $reporter->markdown($report);
            exit;
        }
        if ($format === 'zip' && class_exists(\ZipArchive::class)) {
            $tmp = tempnam(sys_get_temp_dir(), 'training-report-');
            $zip = new \ZipArchive();
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $zip->addFromString($base . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->addFromString($base . '.md', $reporter->markdown($report));
            $zip->close();
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=\"{$base}.zip\"");
            readfile($tmp);
            unlink($tmp);
            exit;
        }
        \json_response(['error' => 'Формат не поддерживается.'], 400);
    }

    public function editCompletedSession(string $id): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/sessions/' . $id);
        }
        try {
            $this->training->updateCompletedSession((int) $id, (int) $user['id'], [
                'session_version' => (int) ($_POST['session_version'] ?? 0),
                'session_rpe' => (int) ($_POST['session_rpe'] ?? 0),
                'wellbeing' => (int) ($_POST['wellbeing'] ?? 0),
                'comment' => $_POST['comment'] ?? null,
            ]);
            $_SESSION['flash_success'] = 'Итог тренировки обновлён; правка добавлена в audit trail.';
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
        }
        \redirect('/sessions/' . $id);
    }

    public function editCompletedSet(string $id): never
    {
        $user = Auth::requireUser();
        $sessionId = max(1, (int) ($_POST['session_id'] ?? 0));
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/sessions/' . $sessionId);
        }
        try {
            $this->training->updateSet((int) $id, (int) $user['id'], [
                'version' => (int) ($_POST['version'] ?? 0),
                'session_version' => (int) ($_POST['session_version'] ?? 0),
                'weight_kg' => (float) ($_POST['weight_kg'] ?? -1),
                'reps' => (int) ($_POST['reps'] ?? 0),
                'rir' => (float) ($_POST['rir'] ?? -1),
            ]);
            $_SESSION['flash_success'] = 'Подход обновлён; итоги, PR и прогрессия пересчитаны.';
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
        }
        \redirect('/sessions/' . $sessionId);
    }

    public function resolveProgression(string $id): never
    {
        $user = Auth::requireUser();
        $sessionId = max(1, (int) ($_POST['session_id'] ?? 0));
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/sessions/' . $sessionId);
        }
        try {
            $accepted = trim((string) ($_POST['accepted_weight_kg'] ?? ''));
            $data = ['status' => $_POST['status'] ?? ''];
            if ($accepted !== '') {
                $data['accepted_weight_kg'] = (float) $accepted;
            }
            $this->training->resolveProgression((int) $id, (int) $user['id'], $data);
            $_SESSION['flash_success'] = 'Решение сохранено. Программа не изменялась автоматически.';
        } catch (\Throwable $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
        }
        \redirect('/sessions/' . $sessionId);
    }

    public function settings(): void
    {
        $user = Auth::requireUser();
        \render('settings', [
            'user' => $user,
            'preview' => $_SESSION['restore_preview'] ?? null,
            'result' => $_SESSION['restore_result'] ?? null,
            'error' => $_SESSION['flash_error'] ?? null,
            'success' => $_SESSION['flash_success'] ?? null,
        ], 'Настройки и данные');
        unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['restore_result']);
    }

    public function saveTheme(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) { $_SESSION['flash_error']='Сессия формы истекла.'; \redirect('/settings'); }
        try { $this->training->updateTheme((int)$user['id'], (string)($_POST['theme']??'')); $_SESSION['flash_success']='Тема сохранена.'; }
        catch (\Throwable $e) { $_SESSION['flash_error']=$e->getMessage(); }
        \redirect('/settings');
    }

    public function restorePreview(): never
    {
        $user = Auth::requireUser();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) { $_SESSION['flash_error']='Сессия формы истекла.'; \redirect('/settings'); }
        try {
            $file=$_FILES['backup']??null;$limit=max(1024,(int)\env('MAX_BACKUP_BYTES','20971520'));
            if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Не удалось принять backup-файл.');
            if(!is_int($file['size']??null)||$file['size']<1||$file['size']>$limit) throw new InvalidArgumentException('Backup пуст или превышает лимит.');
            $extension=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
            if($extension==='json') $json=(string)file_get_contents($file['tmp_name']);
            elseif($extension==='zip'&&class_exists(\ZipArchive::class)){
                $zip=new \ZipArchive();if($zip->open($file['tmp_name'])!==true)throw new InvalidArgumentException('ZIP не читается.');
                $names=[];for($i=0;$i<$zip->numFiles;$i++){ $name=(string)$zip->getNameIndex($i);if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='json'&&!str_contains($name,'../')&&!str_contains($name,'..\\'))$names[]=$name; }
                if(count($names)!==1)throw new InvalidArgumentException('ZIP должен содержать ровно один backup JSON.');
                $stat=$zip->statName($names[0]);if(!is_array($stat)||($stat['size']??0)>$limit)throw new InvalidArgumentException('JSON внутри ZIP превышает лимит.');
                $json=(string)$zip->getFromName($names[0]);$zip->close();
            }else throw new InvalidArgumentException('Поддерживаются backup .json и .zip.');
            $service=new BackupService();$backup=$service->validate($json);$_SESSION['restore_data']=$backup;$_SESSION['restore_preview']=$service->preview($backup,(int)$user['id']);unset($_SESSION['restore_result']);
        } catch(\Throwable $e){unset($_SESSION['restore_data'],$_SESSION['restore_preview']);$_SESSION['flash_error']=$e->getMessage();}
        \redirect('/settings');
    }

    public function restoreConfirm(): never
    {
        $user=Auth::requireUser();
        if(!Csrf::validate($_POST['_csrf']??null)||!is_array($_SESSION['restore_data']??null)||empty($_POST['confirm_merge'])){$_SESSION['flash_error']='Превью устарело или безопасный merge не подтверждён.';\redirect('/settings');}
        try{$service=new BackupService();$result=$service->restore($_SESSION['restore_data'],(int)$user['id']);$_SESSION['restore_result']=$result;$_SESSION['flash_success']=$result['idempotent']?'Эта копия уже была восстановлена: повтор не изменил данные.':'Восстановление завершено транзакционно в режиме merge.';unset($_SESSION['restore_data'],$_SESSION['restore_preview']);}
        catch(\Throwable $e){$_SESSION['flash_error']=$e->getMessage();}
        \redirect('/settings');
    }

    public function restoreCancel(): never
    {
        Auth::requireUser();if(Csrf::validate($_POST['_csrf']??null))unset($_SESSION['restore_data'],$_SESSION['restore_preview']);\redirect('/settings');
    }

    public function cancelSession(string $id): never
    {
        $user=Auth::requireUser();if(!Csrf::validate($_POST['_csrf']??null))\redirect('/sessions/'.$id);
        try{$this->training->cancelSession((int)$id,(int)$user['id'],(int)($_POST['version']??0),!empty($_POST['confirm_cancel']));$_SESSION['flash_success']='Тренировка отменена; план снова доступен для старта.';}
        catch(\Throwable $e){$_SESSION['flash_error']=$e->getMessage();\redirect('/sessions/'.$id);} \redirect('/');
    }

    public function deleteSet(string $id): never
    {
        $user=Auth::requireUser();$sessionId=max(1,(int)($_POST['session_id']??0));if(!Csrf::validate($_POST['_csrf']??null))\redirect('/sessions/'.$sessionId);
        try{$this->training->softDeleteSet((int)$id,(int)$user['id'],(int)($_POST['version']??0),(int)($_POST['session_version']??0),!empty($_POST['confirm_delete']));$_SESSION['flash_success']='Подход мягко удалён; действие записано в audit.';}catch(\Throwable $e){$_SESSION['flash_error']=$e->getMessage();}\redirect('/sessions/'.$sessionId);
    }

    public function deleteMeasurement(string $id): never
    {
        $user=Auth::requireUser();if(!Csrf::validate($_POST['_csrf']??null))\redirect('/measurements');try{$this->training->softDeleteMeasurement((int)$id,(int)$user['id'],!empty($_POST['confirm_delete']));}catch(\Throwable $e){$_SESSION['flash_error']=$e->getMessage();}\redirect('/measurements');
    }

    public function deleteSwimming(string $id): never
    {
        $user=Auth::requireUser();if(!Csrf::validate($_POST['_csrf']??null))\redirect('/swimming/'.$id);try{$this->training->softDeleteSwimming((int)$id,(int)$user['id'],(int)($_POST['version']??0),!empty($_POST['confirm_delete']));$_SESSION['flash_success']='Запись плавания мягко удалена.';}catch(\Throwable $e){$_SESSION['flash_error']=$e->getMessage();\redirect('/swimming/'.$id);}\redirect('/swimming');
    }

    public function backup(): never
    {
        $user = Auth::requireUser();
        $backup = (new BackupService())->export((int) $user['id']);
        $json=json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(($_GET['format']??'json')==='zip'&&class_exists(\ZipArchive::class)){
            $tmp=tempnam(sys_get_temp_dir(),'rhythm-backup-');$zip=new \ZipArchive();$zip->open($tmp,\ZipArchive::OVERWRITE);$zip->addFromString('training-diary-backup-'.gmdate('Y-m-d').'.json',$json);$zip->close();
            header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="training-diary-backup-'.gmdate('Y-m-d').'.zip"');header('Cache-Control: no-store');readfile($tmp);unlink($tmp);exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="training-diary-backup-' . gmdate('Y-m-d') . '.json"');
        header('Cache-Control: no-store');echo $json;
        exit;
    }
}
