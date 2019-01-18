<?php

namespace rdx\transloader;

use Illuminate\Contracts\Translation\Loader as LoaderContract;
use Illuminate\Database\Connection;

class Loader implements LoaderContract {

	protected $db;

	protected $table = 'translations';
	protected $translations = [];

	public function __construct(Connection $db) {
		$this->db = $db;
	}



	protected function fromDb($locale) {
		$query = $this->db->query()
			->select('group', 'name', 'value')
			->from($this->table)
			->where('locale', $locale);

		$translations = [];
		foreach ($query->get() as $trans) {
			$translations[$trans->group][$trans->name] = $trans->value;
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

	protected function addToDb($locale, $group, $name, $value) {
		$this->db->query()->from($this->table)->insert(compact('locale', 'group', 'name', 'value'));
	}

	protected function deleteFromDb($locale, $group, $name) {
		$this->db->query()->from($this->table)->where(compact('locale', 'group', 'name'))->delete();
	}

	protected function path($locale) {
		return storage_path("framework/trans/$locale.php");
	}

	protected function locales() {
		return array_map('basename', glob(resource_path('lang/*')));
	}



	public function count($locale) {
		return $this->db->query()->from($this->table)->where('locale', $locale)->count();
	}

	public function save($locale, $group, $name, $value) {
		$unique = compact('locale', 'group', 'name');
		$this->db->query()->from($this->table)->updateOrInsert($unique, compact('value'));
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
		foreach ($this->locales() as $locale) {
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
		$source = [];
		foreach (glob(resource_path("lang/$locale/*.php")) as $file) {
			$group = substr(basename($file), 0, -4);

			$source[$group] = $this->flattenSource(require $file);
		}

		$db = $this->fromDb($locale);

		// Missing in db
		$added = 0;
		foreach ($source as $group => $translations) {
			foreach ($translations as $key => $translation) {
				if (!isset($db[$group][$key])) {
					$this->addToDb($locale, $group, $key, $translation);
					$added++;
				}
			}
		}

		// Old in db
		$deleted = 0;
		foreach ($db as $group => $translations) {
			foreach ($translations as $key => $translation) {
				if (!isset($source[$group][$key])) {
					$this->deleteFromDb($locale, $group, $key);
					$deleted++;
				}
			}
		}

		return compact('added', 'deleted');
	}

	public function allSourceToDb() {
		$result = [];
		foreach ($this->locales() as $locale) {
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
