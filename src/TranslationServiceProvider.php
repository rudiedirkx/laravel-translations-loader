<?php

namespace rdx\transloader;

use Illuminate\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider {

	/**
	 * Register the service provider.
	 */
	public function register() {
		$this->app->instance('translator.class', Translator::class);
		$this->app->instance('translation.loader.class', TranslationsLoader::class);

		$this->registerLoader();

		$this->app->singleton('translator', function ($app) {
			$loader = $app['translation.loader'];

			// When registering the translator component, we'll need to set the default
			// locale as well as the fallback locale. So, we'll grab the application
			// configuration so we can easily get both of these values from there.
			$locale = $app['config']['app.locale'];

			$class = $app['translator.class'];
			$trans = new $class($loader, $locale);
			$trans->setFallback($app['config']['app.fallback_locale']);

			return $trans;
		});

		$this->commands([
			SyncCommand::class,
		]);
	}

	/**
	 *
	 */
	public function boot() {
		$this->publishes([
			__DIR__ . '/../migrations/' => database_path('migrations'),
		], 'migrations');
	}

	/**
	 * Register the translation line loader.
	 */
	protected function registerLoader() {
		$this->app->singleton('translation.loader', function ($app) {
			$class = $app['translation.loader.class'];
			return new $class($app['db.connection']);
		});

		$this->app->alias('translation.loader', TranslationsLoader::class);
	}

}
