<?php

declare(strict_types=1);

require __DIR__ . '/stage3.php';

$failures = [];
$checks = 0;

$sameSet = $repository->addSet($sessionId, 1, [...$workingInput, 'weight_kg' => 999.0, 'session_version' => 1]);
$check((int) $sameSet['id'] === (int) $working['id'] && (float) $sameSet['weight_kg'] === 60.0, 'receipt возвращает исходный результат до version check');
$throws(fn () => $repository->updateSet((int) $working['id'], 1, ['client_action_id' => 'client-working-1', 'version' => 2, 'session_version' => 10, 'reps' => 1]), 'другого действия', 'один ключ нельзя использовать для другого типа');

$pdo->exec("INSERT INTO workout_plans (id,user_id,external_plan_id,name,workout_type,scheduled_date,status,version,updated_at) VALUES (3,1,'plan-offline','Offline flow','strength','2026-08-25','planned',1,UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO workout_exercises (id,workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,warmup_sets,method_type,instructions) VALUES (3,3,'bench',1,1,8,10,1,3,90,50,0,'normal',NULL)");

$readiness = ['sleep' => 4, 'energy' => 4, 'readiness' => 4, 'client_action_id' => 'session.start:offline-1'];
$offlineSession = $repository->startSession(3, 1, $readiness);
$check($repository->startSession(3, 1, [...$readiness, 'sleep' => 1]) === $offlineSession, 'старт сессии идемпотентен');
$offline = $repository->session($offlineSession, 1);
$exerciseId = (int) $offline['exercises'][0]['id'];

$created = $repository->addSet($offlineSession, 1, ['client_action_id' => 'set.create:offline-1', 'session_version' => 1, 'session_exercise_id' => $exerciseId, 'set_number' => 1, 'set_type' => 'working', 'weight_kg' => 50.0, 'reps' => 9, 'rir' => 2]);
$sameCreated = $repository->addSet($offlineSession, 1, ['client_action_id' => 'set.create:offline-1', 'session_version' => 999, 'session_exercise_id' => $exerciseId, 'set_number' => 99, 'set_type' => 'working', 'weight_kg' => 1.0, 'reps' => 1, 'rir' => 0]);
$check((int) $created['id'] === (int) $sameCreated['id'], 'повтор create set не создаёт дубль');

$updated = $repository->updateSet((int) $created['id'], 1, ['client_action_id' => 'set.update:offline-1', 'session_version' => 2, 'version' => 1, 'reps' => 10]);
$sameUpdated = $repository->updateSet((int) $created['id'], 1, ['client_action_id' => 'set.update:offline-1', 'session_version' => 999, 'version' => 999, 'reps' => 1]);
$check((int) $sameUpdated['reps'] === 10 && (int) $sameUpdated['version'] === (int) $updated['version'], 'повтор update set возвращает receipt');

$waitingInput = ['client_action_id' => 'exercise.status:offline-1', 'session_version' => 3, 'session_exercise_id' => $exerciseId, 'exercise_version' => 2, 'status' => 'waiting'];
$waiting = $repository->setExerciseStatus($offlineSession, 1, $waitingInput);
$sameWaiting = $repository->setExerciseStatus($offlineSession, 1, [...$waitingInput, 'session_version' => 999]);
$check($sameWaiting === $waiting, 'статус упражнения идемпотентен');

$replacementInput = ['client_action_id' => 'exercise.replace:offline-1', 'session_version' => 4, 'session_exercise_id' => $exerciseId, 'exercise_version' => 3, 'actual_exercise_id' => 'row', 'reason' => 'Offline replacement'];
$replacement = $repository->replaceExercise($offlineSession, 1, $replacementInput);
$check($repository->replaceExercise($offlineSession, 1, [...$replacementInput, 'actual_exercise_id' => 'bench']) === $replacement, 'замена упражнения идемпотентна');

$discomfortInput = ['client_action_id' => 'discomfort.create:offline-1', 'session_version' => 5, 'session_exercise_id' => $exerciseId, 'exercise_version' => 4, 'body_area' => 'Плечо', 'intensity' => 2];
$discomfort = $repository->logDiscomfort($offlineSession, 1, $discomfortInput);
$check($repository->logDiscomfort($offlineSession, 1, [...$discomfortInput, 'intensity' => 10]) === $discomfort, 'дискомфорт идемпотентен');

$complete = $repository->setExerciseStatus($offlineSession, 1, ['client_action_id' => 'exercise.status:offline-2', 'session_version' => 6, 'session_exercise_id' => $exerciseId, 'exercise_version' => 4, 'status' => 'completed', 'exercise_rating' => 'normal']);
$finishInput = ['client_action_id' => 'session.finish:offline-1', 'session_version' => 7, 'session_rpe' => 7, 'wellbeing' => 4];
$finishedOffline = $repository->finish($offlineSession, 1, $finishInput);
$sameFinished = $repository->finish($offlineSession, 1, [...$finishInput, 'session_version' => 999, 'session_rpe' => 1]);
$check($finishedOffline['status'] === 'completed' && $sameFinished['status'] === 'completed', 'завершение повторяется через receipt');
$check((int) $pdo->query("SELECT COUNT(*) FROM exercise_sets WHERE workout_session_id={$offlineSession}")->fetchColumn() === 1, 'полный replay оставляет одну копию подхода');
$check((int) $pdo->query("SELECT COUNT(*) FROM discomfort_logs WHERE workout_session_id={$offlineSession}")->fetchColumn() === 1, 'полный replay оставляет одну запись дискомфорта');
$check((int) $pdo->query("SELECT COUNT(*) FROM offline_action_receipts WHERE user_id=1")->fetchColumn() >= 10, 'сервер хранит receipts всех изменяющих действий');

$manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/public/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($manifest['display'] ?? null) === 'standalone' && ($manifest['theme_color'] ?? null) === '#193e2f', 'manifest задаёт standalone и согласованный theme');
$iconSizes = array_column($manifest['icons'] ?? [], 'sizes');
$check(in_array('192x192', $iconSizes, true) && in_array('512x512', $iconSizes, true), 'manifest содержит installability icons 192 и 512');
$check(is_file(dirname(__DIR__) . '/public/icons/icon-180.png') && is_file(dirname(__DIR__) . '/public/icons/icon-512.png'), 'Apple/PWA PNG icons существуют');
$serviceWorker = (string) file_get_contents(dirname(__DIR__) . '/public/service-worker.js');
$check(str_contains($serviceWorker, "url.pathname.includes('/api/')") && str_contains($serviceWorker, 'SKIP_WAITING'), 'Service Worker исключает API и обновляется только по команде');
$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$check(str_contains($layout, 'apple-touch-icon') && str_contains($layout, 'manifest.json'), 'layout подключает PWA и Apple metadata');

if ($failures !== []) {
    fwrite(STDERR, "Stage 4 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 4 checks passed ({$checks}).\n");
