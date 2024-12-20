<?php

namespace rdx\transloader;

use Illuminate\Console\Command;

class CompareCommand extends Command {

	protected $signature = 'translation:compare';

	protected $description = 'Compare lang files structures';

	public function handle() : int {
		$langs = $this->getLangs();

		$allKeys = [];
		$langKeys = [];
		foreach ($langs as $lang) {
			$keys = $this->getLangKeys($lang);
			$langKeys[$lang] = $keys;
			$allKeys = array_merge($allKeys, $keys);
		}

		$allKeys = array_values(array_unique($allKeys));

		$missingAny = false;
		foreach ($langKeys as $lang => $keys) {
			$missing = array_values(array_diff($allKeys, $keys));
			if (count($missing)) $missingAny = true;
			echo "Missing in `$lang`:\n";
			print_r($missing);
			echo "\n";
		}

		if ($missingAny) {
			return 1;
		}

		$differentOrderAny = false;
		foreach ($langKeys as $lang => $keys) {
			if ($firstDifferentKey = $this->findFirstOrderDifference($allKeys, $keys)) {
				$differentOrderAny = true;
				echo "But in different order in `$lang`:\n";
				echo "- $firstDifferentKey\n";
			}
		}

		if ($differentOrderAny) {
			return 2;
		}

		return 0;
	}

	/**
	 * @return list<string>
	 */
	protected function getLangs() : array {
		$langs = glob(resource_path("lang/*"));
		$langs = array_map(fn($path) => basename($path), $langs);
		return $langs;
	}

	/**
	 * @return list<string>
	 */
	protected function getLangKeys(string $lang) : array {
		$groups = $this->getGroups($lang);
		$keys = [];
		foreach ($groups as $group) {
			$keys = array_merge($keys, $this->getGroupKeys($lang, $group));
		}

		return $keys;
	}

	/**
	 * @return list<string>
	 */
	protected function getGroups(string $lang) : array {
		$groups = glob(resource_path("lang/$lang/*.php"));
		$groups = array_map(fn($path) => substr(basename($path), 0, -4), $groups);
		return $groups;
	}

	/**
	 * @return list<string>
	 */
	protected function getGroupKeys(string $lang, string $group) : array {
		$filepath = resource_path("lang/$lang/$group.php");
		$dimensional = include $filepath;
		return $this->flattenKeys($group, $dimensional);
	}

	/**
	 * @param array<string, mixed> $dimensional
	 * @return list<string>
	 */
	protected function flattenKeys(string $prefix, array $dimensional) : array {
		$flat = [];
		foreach ($dimensional as $key => $trans) {
			if (is_array($trans)) {
				$flat = array_merge($flat, $this->flattenKeys("$prefix.$key", $trans));
			}
			else {
				$flat[] = "$prefix.$key";
			}
		}

		return $flat;
	}

	/**
	 * @param list<string> $allKeys
	 * @param list<string> $langKeys
	 */
	protected function findFirstOrderDifference(array $allKeys, array $langKeys) : ?string {
		foreach ($allKeys as $i => $key) {
			if ($key !== ($langKeys[$i] ?? null)) {
				return $langKeys[$i] ?? $key;
			}
		}

		return null;
	}

}
