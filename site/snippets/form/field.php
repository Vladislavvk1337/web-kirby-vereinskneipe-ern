<?php
/**
 * Formularfeld mit Label, Hinweis und Fehlermeldung direkt am Feld.
 *
 * @var string $name
 * @var string $label
 * @var string|null $type      text|email|tel|date|textarea|select
 * @var bool|null   $required
 * @var string|null $hint
 * @var array       $values
 * @var array       $errors
 * @var array|null  $options   für select
 * @var string|null $autocomplete
 */
$type     = $type ?? 'text';
$hint     = $hint ?? null;
$required = $required ?? false;
$id       = 'feld-' . $name;
$value    = (string)($values[$name] ?? '');
$error    = $errors[$name] ?? null;
$describe = array_filter([$hint ? $id . '-hinweis' : null, $error ? $id . '-fehler' : null]);
$attrs    = [
	'id'               => $id,
	'name'             => $name,
	'required'         => $required,
	'aria-invalid'     => $error ? 'true' : null,
	'aria-describedby' => $describe ? implode(' ', $describe) : null,
	'autocomplete'     => $autocomplete ?? null,
	'maxlength'        => $maxlength ?? null,
];
?>
<div class="field<?= $error ? ' field--error' : '' ?>">
  <label for="<?= $id ?>"><?= esc($label) ?><?php if (!$required): ?> <span class="field__optional">(freiwillig)</span><?php endif ?></label>
  <?php if (!empty($hint)): ?><p class="field__hint" id="<?= $id ?>-hinweis"><?= esc($hint) ?></p><?php endif ?>
  <?php if ($error): ?><p class="field__error" id="<?= $id ?>-fehler"><?php snippet('icon', ['name' => 'alert']) ?><span><span class="visually-hidden">Fehler: </span><?= esc($error) ?></span></p><?php endif ?>
  <?php if ($type === 'textarea'): ?>
  <?= Html::tag('textarea', [esc($value)], [...$attrs, 'rows' => $rows ?? 5]) ?>
  <?php elseif ($type === 'select'): ?>
  <select <?= Html::attr($attrs) ?>>
    <option value="">Bitte auswählen</option>
    <?php foreach ($options as $key => $optionLabel): ?>
    <option value="<?= esc($key) ?>"<?= $value === (string)$key ? ' selected' : '' ?>><?= esc($optionLabel) ?></option>
    <?php endforeach ?>
  </select>
  <?php else: ?>
  <input <?= Html::attr([...$attrs, 'type' => $type, 'value' => $value, 'inputmode' => $inputmode ?? null, 'min' => $min ?? null]) ?>>
  <?php endif ?>
</div>
