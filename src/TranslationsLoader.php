<?php

namespace rdx\transloader;

use Illuminate\Contracts\Translation\Loader as LoaderContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

class TranslationsLoader implements LoaderContract {

	protected $db;
	protected $config;

	protected $table = 'translations';
	protected $translations = [];

	public function __construct(array $config, Connection $db) {
		$this->config = $config;
		$this->db = $db;
	}



	protected function fromDb(string $locale) : array {
		$dontLoad = $this->config['dont_load_translations'] ?? [];

		$query = $this->query()
			->select('group', 'name', 'value')
			->where('locale', $locale);

		$translations = [];
		foreach ($query->get() as $trans) {
			if (!in_array($trans->value, $dontLoad, true)) {
				$translations[$trans->group][$trans->name] = $trans->value;
			}
		}

		return $translations;
	}

	protected function flattenSource(array $list) : array {
		$output = [];

		$add = function($list, $prefix = []) use (&$add, &$output) {
			foreach ($list as $name => $value) {
				$names = array_merge($prefix, [$name]);
				if (is_array($value)) {
					$add($value, $names);
				}
				else {
					$output[implode('.', $names)] = $value;
				}
			}
		};

		$add($list);
		return $output;
	}

	public function query() : Builder {
		return $this->db->query()->from($this->table);
	}

	public function addsToDb(array $records) : void {
		$this->query()->insert($records);
	}

	protected function deleteFromDb(string $locale, string $group, string $name) : void {
		$this->query()->where(compact('locale', 'group', 'name'))->delete();
	}

	protected function path(string $locale) : string {
		$locale = basename($locale);
		return storage_path("framework/trans/$locale.php");
	}

	protected function cachedLocales() : array {
		return array_map(function($filename) {
			return substr(basename($filename), 0, -4);
		}, glob(storage_path('framework/trans/*.php')));
	}

	protected function sourcedLocales() : array {
		return array_map('basename', glob(resource_path('lang/*')));
	}



	public function count(string $locale) : int {
		return $this->query()->where('locale', $locale)->count();
	}

	protected function insert(string $locale, string $group, string $name, string $value) : void {
		$this->query()->insert(compact('locale', 'group', 'name', 'value'));
	}

	protected function update(string $locale, string $group, string $name, string $value) : void {
		$this->query()->where(compact('locale', 'group', 'name'))->update(compact('value'));
	}

	public function save(string $locale, string $group, string $name, string $value) : bool {
		$curValue = $this->getTranslation($locale, $group, $name);
		if ($curValue === null) {
			$this->insert($locale, $group, $name, $value);
			return true;
		}
		elseif ($curValue !== $value) {
			$this->update($locale, $group, $name, $value);
			return true;
		}
		return false;
	}

	public function saveIfEmpty(string $locale, string $group, string $name, string $value) : bool {
		$curValue = $this->getTranslation($locale, $group, $name);
		if ($curValue === null) {
			$this->insert($locale, $group, $name, $value);
			return true;
		}
		return false;
	}

	public function saveOrDelete(string $locale, string $group, string $name, string $value) : bool {
		if ($value === '') {
			return $this->delete($locale, $group, $name);
		}
		else {
			return $this->save($locale, $group, $name, $value);
		}
	}

	public function delete(string $locale, string $group, string $name) : bool {
		$unique = compact('locale', 'group', 'name');
		return $this->query()->where($unique)->delete() > 0;
	}

	public function getTranslation(string $locale, string $group, string $name) : ?string {
		$unique = compact('locale', 'group', 'name');
		$curRecord = $this->query()->where($unique)->first('value');
		return $curRecord ? $curRecord->value : null;
	}

	public function clear() {
		$this->query()->delete();
	}

	protected function deleteStaleFromDb(array $baseLocales) : int {
		$fullkeys = array_column(\DB::select("
			select distinct concat(`group`, '.', name) fullkey
			from translations
			where concat(`group`, '.', name) not in (
				select concat(`group`, '.', name) fullkey
				from translations
				where locale in ('en', 'nl')
			)
		"), 'fullkey');

		if (count($fullkeys)) {
			$placeholders = implode(', ', array_fill(0, count($fullkeys), '?'));
			return \DB::delete("
				delete from translations
				where concat(`group`, '.', name) in ($placeholders)
			", $fullkeys);
		}

		return 0;
	}

	public function sync() {
		$result = $this->allSourceToDb();
		$result['__']['stale'] = $this->deleteStaleFromDb($this->sourcedLocales());
		$this->uncacheAll();

		return $result;
	}

	public function uncacheAll() {
		foreach ($this->cachedLocales() as $locale) {
			$this->uncache($locale);
		}
	}

	public function uncache(string $locale) {
		if (file_exists($file = $this->path($locale))) {
			unlink($file);
		}
	}

	public function dbToCache(string $locale) {
		$translations = $this->fromDb($locale);

		$file = $this->path($locale);
		$dir = dirname($file);
		is_dir($dir) or mkdir($dir);
		file_put_contents($file, "<?php\nreturn " . var_export($translations, true) . ';');
		@chmod($file, 0666);

		return $translations;
	}

	public function sourceToDb(string $locale) {
		$locale = basename($locale);

		$source = [];
		foreach (glob(resource_path("lang/$locale/*.php")) as $file) {
			$group = substr(basename($file), 0, -4);

			$source[$group] = $this->flattenSource(require $file);
		}

		$db = $this->fromDb($locale);

		[$added, $deleted] = \DB::transaction(function() use ($source, $db, $locale) {
			// Missing in db
			$add = [];
			foreach ($source as $group => $translations) {
				foreach ($translations as $name => $value) {
					if (!isset($db[$group][$name])) {
						$add[] = compact('locale', 'group', 'name', 'value');
					}
				}
			}
			foreach (array_chunk($add, 100) as $chunk) {
				$this->addsToDb($chunk);
			}

			// Old in db
			$deleted = 0;
			foreach ($db as $group => $translations) {
				foreach ($translations as $name => $value) {
					if (!isset($source[$group][$name])) {
						$this->deleteFromDb($locale, $group, $name);
						$deleted++;
					}
				}
			}
			return [count($add), $deleted];
		});

		return compact('added', 'deleted');
	}

	public function allSourceToDb() {
		$result = [];
		foreach ($this->sourcedLocales() as $locale) {
			$result[$locale] = $this->sourceToDb($locale);
		}

		return $result;
	}



	/**
	 * Load the messages for the given locale.
	 */
	public function load($locale, $group, $namespace = null) {
		// Load from memory
		if (isset($this->translations[$locale])) {
			return $this->translations[$locale][$group] ?? [];
		}

		// Load from file cache
		if (file_exists($file = $this->path($locale))) {
			$this->translations[$locale] = require $file;
			return $this->translations[$locale][$group] ?? [];
		}

		// Make file cache
		$translations = $this->dbToCache($locale);

		$this->translations[$locale] = $translations;
		return $this->translations[$locale][$group] ?? [];
	}

	/**
	 * Add a new namespace to the loader.
	 */
	public function addNamespace($namespace, $hint) {
	}

	/**
	 * Add a new JSON path to the loader.
	 */
	public function addJsonPath($path) {
	}

	/**
	 * Get an array of all the registered namespaces.
	 */
	public function namespaces() {
		return [];
	}

}
