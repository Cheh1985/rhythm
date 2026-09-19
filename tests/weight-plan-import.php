<?php
declare(strict_types=1);
require __DIR__ . '/stage2.php';
restore_exception_handler(); // Let CLI failures return a nonzero exit status.

$plan = json_decode(file_get_contents(__DIR__.'/fixtures/training-plan/full-body-a.json'), true, 512, JSON_THROW_ON_ERROR);
$plan['schema_version'] = '1.1';
$plan['plan_id'] = 'mixed-weight-units';
$plan['program']['program_id'] = 'mixed-weight-program';
$plan['exercises'][0]['weight'] = 100;
$plan['exercises'][0]['weight_unit'] = 'lb';
$plan['exercises'][1]['weight'] = 22.5;
$plan['exercises'][1]['weight_unit'] = 'kg';
$service->validate($plan);
$preview = $service->preview($plan, 1);
if ($preview['exercises'][0]['weight_value'] !== 100 || $preview['exercises'][0]['weight_unit'] !== 'lb') throw new RuntimeException('Preview lost source units');
$service->import($plan, 1, true);
$row = $pdo->query("SELECT we.* FROM workout_exercises we JOIN workout_plans p ON p.id=we.workout_plan_id WHERE p.external_plan_id='mixed-weight-units' ORDER BY sequence_no")->fetch();
if ((float)$row['planned_weight_value'] !== 100.0 || $row['planned_weight_unit'] !== 'lb' || (float)$row['planned_weight_kg'] !== 45.359237) throw new RuntimeException('Import lost source or normalized weight');
$legacy = $plan;
$legacy['schema_version'] = '1.0';
try { $service->validate($legacy); throw new RuntimeException('v1.0 silently accepted pound extension'); } catch (InvalidArgumentException) {}
unset($legacy['exercises'][0]['weight_unit'], $legacy['exercises'][1]['weight_unit']);
$service->validate($legacy);
fwrite(STDOUT, "Mixed-unit plan import and v1.0 compatibility checks passed.\n");
