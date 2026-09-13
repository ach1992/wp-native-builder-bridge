<?php
/** Static inspection only: never executes the inspected PHP or SQL. */
// Frozen reviewed SQL and exact bound arguments. Do not derive these from the inspected file.
$expected = array (
  'SELECT meta_id, term_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '(int)$term_id',
    2 => '$key',
  ),
  'SELECT MIN(meta_key) AS meta_key, COUNT(*) AS row_count FROM %i WHERE term_id = %d AND meta_key IS NOT NULL GROUP BY CAST(meta_key AS BINARY) ORDER BY CAST(meta_key AS BINARY) LIMIT %d OFFSET %d' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '(int)$term_id',
    2 => 'max(1,min(101,(int)$limit))',
    3 => 'max(0,min(999900,(int)$offset))',
  ),
  'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND term_id = %d' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '$key',
    2 => '$term_id',
  ),
  'INSERT INTO %i (meta_id, term_id, meta_key, meta_value) SELECT NULLIF(%d, 0), tt.term_id, %s, NULL FROM %i AS tt INNER JOIN %i AS t ON t.term_id = tt.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = tt.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE tt.term_id = %d AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL LOCK IN SHARE MODE' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '(int)$row[\'meta_id\']',
    2 => '(string)$row[\'key\']',
    3 => '$wpdb->term_taxonomy',
    4 => '$wpdb->terms',
    5 => '$wpdb->term_taxonomy',
    6 => '(int)$row[\'term_id\']',
    7 => '$row[\'target_term_taxonomy_id\']',
    8 => '$row[\'target_taxonomy\']',
  ),
  'INSERT INTO %i (meta_id, term_id, meta_key, meta_value) SELECT NULLIF(%d, 0), tt.term_id, %s, %s FROM %i AS tt INNER JOIN %i AS t ON t.term_id = tt.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = tt.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE tt.term_id = %d AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL LOCK IN SHARE MODE' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '(int)$row[\'meta_id\']',
    2 => '(string)$row[\'key\']',
    3 => '$row[\'raw_value\']',
    4 => '$wpdb->term_taxonomy',
    5 => '$wpdb->terms',
    6 => '$wpdb->term_taxonomy',
    7 => '(int)$row[\'term_id\']',
    8 => '$row[\'target_term_taxonomy_id\']',
    9 => '$row[\'target_taxonomy\']',
  ),
  'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = NULL WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND m.meta_value IS NULL AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '$wpdb->term_taxonomy',
    2 => '$wpdb->terms',
    3 => '$wpdb->term_taxonomy',
    4 => '(int)$meta_id',
    5 => '(int)$term_id',
    6 => '(string)$key',
    7 => '$target[\'target_term_taxonomy_id\']',
    8 => '$target[\'target_taxonomy\']',
  ),
  'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = %s WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND m.meta_value IS NULL AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '$wpdb->term_taxonomy',
    2 => '$wpdb->terms',
    3 => '$wpdb->term_taxonomy',
    4 => '$new_raw',
    5 => '(int)$meta_id',
    6 => '(int)$term_id',
    7 => '(string)$key',
    8 => '$target[\'target_term_taxonomy_id\']',
    9 => '$target[\'target_taxonomy\']',
  ),
  'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = NULL WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY) AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '$wpdb->term_taxonomy',
    2 => '$wpdb->terms',
    3 => '$wpdb->term_taxonomy',
    4 => '(int)$meta_id',
    5 => '(int)$term_id',
    6 => '(string)$key',
    7 => '$expected_raw',
    8 => '$target[\'target_term_taxonomy_id\']',
    9 => '$target[\'target_taxonomy\']',
  ),
  'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = %s WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY) AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '$wpdb->term_taxonomy',
    2 => '$wpdb->terms',
    3 => '$wpdb->term_taxonomy',
    4 => '$new_raw',
    5 => '(int)$meta_id',
    6 => '(int)$term_id',
    7 => '(string)$key',
    8 => '$expected_raw',
    9 => '$target[\'target_term_taxonomy_id\']',
    10 => '$target[\'target_taxonomy\']',
  ),
  'DELETE m FROM %i AS m LEFT JOIN %i AS tt ON tt.term_id = m.term_id LEFT JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND m.meta_value IS NULL AND ( ( tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL AND t.term_id IS NOT NULL ) OR ( %d = 1 AND tt.term_taxonomy_id IS NULL AND t.term_id IS NULL ) )' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '$wpdb->term_taxonomy',
    2 => '$wpdb->terms',
    3 => '$wpdb->term_taxonomy',
    4 => '(int)$meta_id',
    5 => '(int)$term_id',
    6 => '(string)$key',
    7 => '$target[\'target_term_taxonomy_id\']',
    8 => '$target[\'target_taxonomy\']',
    9 => '(int)$allow_missing',
  ),
  'DELETE m FROM %i AS m LEFT JOIN %i AS tt ON tt.term_id = m.term_id LEFT JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY) AND ( ( tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL AND t.term_id IS NOT NULL ) OR ( %d = 1 AND tt.term_taxonomy_id IS NULL AND t.term_id IS NULL ) )' =>
  array (
    0 => '$wpdb->termmeta',
    1 => '$wpdb->term_taxonomy',
    2 => '$wpdb->terms',
    3 => '$wpdb->term_taxonomy',
    4 => '(int)$meta_id',
    5 => '(int)$term_id',
    6 => '(string)$key',
    7 => '$expected_raw',
    8 => '$target[\'target_term_taxonomy_id\']',
    9 => '$target[\'target_taxonomy\']',
    10 => '(int)$allow_missing',
  ),
);
$path = $argv[1] ?? dirname( __DIR__ ) . '/src/Support/class-term-meta-store.php';
$source = file_get_contents( $path );
if ( false === $source ) { fwrite( STDERR, "ERROR: term-meta store cannot be inspected.\n" ); exit( 1 ); }
$fail = static function ( $message ) { fwrite( STDERR, 'ERROR: term-meta confinement: ' . $message . "\n" ); exit( 1 ); };
$tokens = array_values( array_filter( token_get_all( $source ), static fn( $t ) => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
$text = static fn( $t ) => is_array( $t ) ? $t[1] : $t;
$sequence = static function ( $index, $parts ) use ( $tokens, $text ) { foreach ( $parts as $offset => $part ) { if ( ! isset( $tokens[ $index + $offset ] ) || $text( $tokens[ $index + $offset ] ) !== $part ) { return false; } } return true; };
$arguments = static function ( $opening ) use ( $tokens, $text, $fail ) {
    $depth = 0; $result = array(); $current = array();
    for ( $i = $opening + 1; $i < count( $tokens ); ++$i ) {
        $value = $text( $tokens[ $i ] );
        if ( 0 === $depth && ')' === $value ) { $result[] = $current; return $result; }
        if ( 0 === $depth && ',' === $value ) { $result[] = $current; $current = array(); continue; }
        $current[] = $tokens[ $i ];
        if ( in_array( $value, array( '(', '[', '{' ), true ) ) { ++$depth; }
        if ( in_array( $value, array( ')', ']', '}' ), true ) ) { --$depth; }
        if ( $depth < 0 ) { $fail( 'unbalanced prepared arguments' ); }
    }
    $fail( 'unterminated prepared arguments' );
};
$seen = array();
$calls = array_fill_keys( array( 'prepare', 'query', 'get_results', 'get_var' ), 0 );
foreach ( $tokens as $index => $token ) {
    if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) { continue; }
    if ( isset( $tokens[ $index - 1 ] ) && is_array( $tokens[ $index - 1 ] ) && T_GLOBAL === $tokens[ $index - 1 ][0] && ';' === ( $tokens[ $index + 1 ] ?? null ) ) { continue; }
    if ( ! $sequence( $index + 1, array( '->' ) ) || ! isset( $tokens[ $index + 2 ] ) || ! is_array( $tokens[ $index + 2 ] ) || T_STRING !== $tokens[ $index + 2 ][0] ) { $fail( 'database alias or dynamic member access' ); }
    $member = $tokens[ $index + 2 ][1];
    if ( in_array( $member, array( 'termmeta', 'terms', 'term_taxonomy', 'last_error', 'insert_id' ), true ) ) {
        if ( in_array( $text( $tokens[ $index + 3 ] ?? '' ), array( '=', '+=', '-=', '.=', '??=', '++', '--' ), true ) || in_array( $text( $tokens[ $index - 1 ] ?? '' ), array( '++', '--' ), true ) ) { $fail( 'database property assignment' ); }
        continue;
    }
    if ( ! array_key_exists( $member, $calls ) || '(' !== ( $tokens[ $index + 3 ] ?? null ) ) { $fail( 'unexpected database member' ); }
    ++$calls[ $member ];
    if ( 'prepare' === $member ) {
        $args = $arguments( $index + 3 );
        $literal = $args[0][0] ?? null;
        if ( 1 !== count( $args[0] ) || ! is_array( $literal ) || T_CONSTANT_ENCAPSED_STRING !== $literal[0] || "'" !== $literal[1][0] ) { $fail( 'non-literal or concatenated SQL template' ); }
        $query = substr( $literal[1], 1, -1 );
        $actual = array_map( static fn( $arg ) => implode( '', array_map( $text, $arg ) ), array_slice( $args, 1 ) );
        if ( ! isset( $expected[ $query ] ) || $expected[ $query ] !== $actual ) { $fail( 'unexpected SQL template, table, or identity argument' ); }
        $seen[] = $query;
    } elseif ( ! $sequence( $index + 4, array( '$wpdb', '->', 'prepare', '(' ) ) ) { $fail( 'unprepared query/read input' ); }
}
$expected_queries = array_keys( $expected ); sort( $seen ); sort( $expected_queries );
if ( $seen !== $expected_queries || $calls !== array( 'prepare' => 11, 'query' => 8, 'get_results' => 2, 'get_var' => 1 ) ) { $fail( 'persistence template/call inventory changed' ); }
echo "PASS: term-meta literal SQL, exact identity arguments, and database-member confinement.\n";
