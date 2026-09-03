<?php
$languageReturnTo = (string) ($_SERVER['REQUEST_URI'] ?? '/');
if (!str_starts_with($languageReturnTo, '/') || str_starts_with($languageReturnTo, '//')) $languageReturnTo = '/';
?>
<form method="post" action="<?= e(url('/language')) ?>" class="language-switch" aria-label="Язык">
    <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
    <input type="hidden" name="return_to" value="<?= e($languageReturnTo) ?>">
    <button type="submit" name="locale" value="ru" aria-pressed="<?= locale() === 'ru' ? 'true' : 'false' ?>">RU</button>
    <span aria-hidden="true">/</span>
    <button type="submit" name="locale" value="en" aria-pressed="<?= locale() === 'en' ? 'true' : 'false' ?>">EN</button>
</form>
