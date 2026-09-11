<?php
$messages = array_merge(
	require __DIR__ . '/fa_IR-parts/part-1.php',
	require __DIR__ . '/fa_IR-parts/part-2.php',
	require __DIR__ . '/fa_IR-parts/part-3.php',
	require __DIR__ . '/fa_IR-parts/part-4.php',
	require __DIR__ . '/fa_IR-parts/part-5.php'
);

return array(
	'project-id-version' => 'WP Native Builder Bridge 0.1.2',
	'language'           => 'fa_IR',
	'messages'           => $messages,
);
