<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/
/*
| Open-S3 routes. Specific literal routes are matched first; everything else
| (bucket/object paths, which may contain arbitrary '/') falls through to the
| S3 controller's catch-all.
*/
$route['healthz'] = 'health/index';

// Deploy option 3 (shared hosting): cron hits this over plain HTTP instead
// of running a CLI worker — see application/controllers/Cronjobs.php.
$route['cronjobs/process'] = 'cronjobs/process';

$route['internal/presign'] = 'internal/presign';
$route['internal/buckets/(.+)/policy'] = 'internal/policy/$1';
$route['internal/events'] = 'internal/events';
$route['internal/uploads/(.+)'] = 'internal/upload/$1';

// CLI-only migration runner (see cli/migrate.php) — not reachable over HTTP,
// but still needs an explicit route or the catch-all below would swallow it.
$route['cli_migrate/run'] = 'cli_migrate/run';

// Browser-accessible admin-account creation for shared hosting with no
// SSH/CLI access (see application/controllers/Setup.php) — gated by
// SECRET_ACCESS_KEY, same trust level as every other privileged endpoint.
$route['setup/create-admin'] = 'setup/create_admin';

// Admin panel (docs/plans_v2.md) — session-based, entirely separate from the
// S3 API auth above. Explicit routes needed because the catch-all matches
// everything not previously matched.
$route['admin'] = 'admin/dashboard/index';
$route['admin/login'] = 'admin/auth/login';
$route['admin/logout'] = 'admin/auth/logout';
$route['admin/buckets'] = 'admin/buckets/index';
$route['admin/buckets/new'] = 'admin/buckets/create';
$route['admin/buckets/(.+)/edit'] = 'admin/buckets/edit/$1';
$route['admin/buckets/(.+)/delete'] = 'admin/buckets/delete/$1';
$route['admin/buckets/(.+)/objects/tree'] = 'admin/objects/tree/$1';
$route['admin/buckets/(.+)/objects/preview'] = 'admin/objects/preview/$1';
$route['admin/buckets/(.+)/objects/download'] = 'admin/objects/download/$1';
$route['admin/buckets/(.+)/objects'] = 'admin/objects/index/$1';
$route['admin/events'] = 'admin/events/index';
$route['admin/events/(\d+)/redispatch'] = 'admin/events/redispatch/$1';

$route['default_controller'] = 's3';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;
$route['(.*)'] = 's3/index/$1';
