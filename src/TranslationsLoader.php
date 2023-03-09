<?php

namespace rdx\transloader;

use Illuminate\Contracts\Translation\Loader as LoaderContract;
use Illuminate\Database\Connection;

class TranslationsLoader implements LoaderContract {

	protected $db;
	protected $config;

	protected $table = 'translations';
	protected $translations = [];

	public function __construct(array $config, Connection $db) {
		$this->config = $config;
		$this->db = $db;
	}



	protected function fromDb($locale) {
		$dontLoad = $this->config['dont_load_translations'] ?? [];

		$query = $this->db->query()
			->select('group', 'name', 'value')
			->from($this->table)
			->where('locale', $locale);

		$translations = [];
		foreach ($query->get() as $trans) {
			if (!in_array($trans->value, $dontLoad, true)) {
				$translations[$trans->group][$trans->name] = $trans->value;
			}
		}

		return $translations;
	}

	protected function flattenSource($list) {
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

	protected function addsToDb(array $records) {
		$this->db->query()->from($this->table)->insert($records);
	}

	protected function deleteFromDb($locale, $group, $name) {
		$this->db->query()->from($this->table)->where(compact('locale', 'group', 'name'))->delete();
	}

	protected function path($locale) {
		$locale = basename($locale);
		return storage_path("framework/trans/$locale.php");
	}

	protected function cachedLocales() {
		return array_map(function($filename) {
			return substr(basename($filename), 0, -4);
		}, glob(storage_path('framework/trans/*.php')));
	}

	protected function sourcedLocales() {
		return array_map('basename', glob(resource_path('lang/*')));
	}



	public function count($locale) {
		return $this->db->query()->from($this->table)->where('locale', $locale)->count();
	}

	public function save($locale, $group, $name, $value) {
		$unique = compact('locale', 'group', 'name');
		$this->db->query()->from($this->table)->updateOrInsert($unique, compact('value'));
	}

	public function delete($locale, $group, $name) {
		$unique = compact('locale', 'group', 'name');
		$this->db->query()->from($this->table)->where($unique)->delete();
	}

	public function saveOrDelete($locale, $group, $name, $value) {
		if ($value === '') {
			$this->delete($locale, $group, $name);
		}
		else {
			$this->save($locale, $group, $name, $value);
		}
	}

	public function clear() {
		$this->db->query()->from($this->table)->delete();
	}

	public function sync() {
		$result = $this->allSourceToDb();
		$this->uncacheAll();

		return $result;
	}

	public function uncacheAll() {
		foreach ($this->cachedLocales() as $locale) {
			$this->uncache($locale);
		}
	}

	public function uncache($locale) {
		if (file_exists($file = $this->path($locale))) {
			unlink($file);
		}
	}

	public function dbToCache($locale) {
		$translations = $this->fromDb($locale);

		$file = $this->path($locale);
		$dir = dirname($file);
		is_dir($dir) or mkdir($dir);
		file_put_contents($file, "<?php\nreturn " . var_export($translations, true) . ';');
		@chmod($file, 0666);

		return $translations;
	}

	public function sourceToDb($locale) {
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
