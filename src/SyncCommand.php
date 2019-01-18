<?php

namespace rdx\transloader;

use Illuminate\Console\Command;

class SyncCommand extends Command {

	protected $signature = 'translation:sync
		{--clear}
	';
	protected $description = 'Sync translations to db';

	public function handle(TranslationsLoader $loader) {
		$clear = $this->option('clear');
		if ($clear) {
			$loader->clear();
		}

		$result = $loader->sync();

		print_r($result);
	}

}
