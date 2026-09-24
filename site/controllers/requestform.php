<?php

use Kneipe\FormTimer;
use Kneipe\RequestForm;

return function ($kirby, $page) {
	$form   = new RequestForm($kirby);
	$slots  = $form->slots();
	$values = [];
	$errors = [];
	$notice = null;

	if ($kirby->request()->is('POST') === true) {
		$request = $kirby->request();
		$csrf    = $request->body()->get('csrf');
		$result  = $form->handle(
			$request->body()->toArray(),
			is_string($csrf) ? $csrf : null,
			$request->header('Origin'),
			$kirby->visitor()->ip() ?? '0.0.0.0'
		);

		// Erfolg (und erkannte Bots): Post/Redirect/Get ohne Formulardaten in der URL
		if ($result['status'] === 'ok' || $result['status'] === 'spam') {
			go($page->find('danke')?->url() ?? $page->url(), 303);
		}

		$values = $result['values'];
		$errors = $result['errors'];
		$notice = match ($result['status']) {
			'csrf'      => 'Das Formular war zu lange geöffnet oder die Sitzung ist abgelaufen. Bitte prüft eure Angaben und sendet das Formular noch einmal ab.',
			'origin'    => 'Die Anfrage konnte nicht zugeordnet werden. Bitte ladet die Seite neu und versucht es noch einmal.',
			'ratelimit' => 'Von eurem Anschluss kamen gerade sehr viele Anfragen. Bitte versucht es in einer Stunde noch einmal oder schreibt uns eine E-Mail.',
			default     => null,
		};

		$kirby->response()->code($result['status'] === 'ratelimit' ? 429 : 422);
	} else {
		// Vorauswahl aus einem Link „Diesen Termin anfragen“
		$wanted = $kirby->request()->get('termin');
		$wanted = is_string($wanted) ? $wanted : '';

		if (isset($slots[$wanted]) === true) {
			$values['slot'] = $wanted;
		}
	}

	return [
		'freeSlots' => $slots,
		'values'  => $values,
		'errors'  => $errors,
		'notice'  => $notice,
		'csrf'    => csrf(),
		'timer'   => FormTimer::issue(kneipe()->secret()),
		'styles'  => ['form'],
	];
};
