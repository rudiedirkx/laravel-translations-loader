<?php

namespace rdx\transloader;

use App\Models\Locale;
use Illuminate\Translation\Translator as BaseTranslator;

class Translator extends BaseTranslator {

	/**
	 * Temporarily overwrite the current locale, do something, and switch back.
	 */
	public function tempLocale($locale, callable $callback) {
		$app = app();

		// Save old, set new.
		$originalLocale = NULL;
		if ($locale) {
			$originalLocale = $app->getLocale();
			$app->setLocale($locale);
		}

		// Do whatever the caller wants, in the new locale.
		$return = $callback();

		// Reset old locale.
		if ($originalLocale) {
			$app->setLocale($originalLocale);
		}

		return $return;
	}

}
