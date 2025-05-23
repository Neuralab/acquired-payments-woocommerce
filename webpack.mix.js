/**
 * Laravel Mix config.
*/

/* eslint-env node */
/* eslint-disable unicorn/prefer-module */

/**
 * Require dependencies.
 */
// Laravel mix
const mix = require('laravel-mix');
// Laravel Mix Clean
require('laravel-mix-clean');

/**
 * Define the variables we need.
 */
// Source path
// This is the path where your raw assets are.
const sourcePath = 'assets/src';

// Distribution path
// This is the path where your compiled assets are.
const distributionPath = 'assets/dist';

/**
 * Laravel Mix settings.
 */
// Disable success messages.
mix.disableSuccessNotifications();

// Set public path
// This is a folder where all your assets will get compiled to.
mix.setPublicPath(distributionPath);

// Clean distributionPath
// This removes the folder where we build all of our assets when we start Mix.
// Prevents having stale assets like images in distributionPath that remain after we remove them in sourcePath.
mix.clean({
  // Ignore some files.
  cleanOnceBeforeBuildPatterns: [
    '**/*',
  ],
});

// Exclude dependencies
// Exclude dependencies that shouldn't be compiled in output bundle.
mix.webpackConfig({
  externals: {
    jquery: 'jQuery',
  },
});

/**
 * Build plugin assets.
 */
mix.js(`${sourcePath}/js/acfw-admin-order.js`, 'js');
mix.js(`${sourcePath}/js/acfw-admin-settings.js`, 'js');
mix.js(`${sourcePath}/js/acfw-block-checkout.js`, 'js');
