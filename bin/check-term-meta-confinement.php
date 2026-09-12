<?php
/** Static source inspection only. Never executes the inspected PHP or any SQL. */
$path = $argv[1] ?? dirname( __DIR__ ) . '/src/Support/class-term-meta-store.php';
$source = file_get_contents( $path );
if ( false === $source ) { fwrite( STDERR, "ERROR: term-meta store cannot be inspected.\n" ); exit( 1 ); }
$fail = static function ( $message ) { fwrite( STDERR, 'ERROR: term-meta confinement: ' . $message . "\n" ); exit( 1 ); };
$expected = array(
    'SELECT meta_id, term_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2',
    'SELECT MIN(meta_key) AS meta_key, COUNT(*) AS row_count FROM %i WHERE term_id = %d AND meta_key IS NOT NULL GROUP BY CAST(meta_key AS BINARY) ORDER BY CAST(meta_key AS BINARY) LIMIT %d OFFSET %d',
    'UPDATE %i SET meta_value = NULL WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
    'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
    'UPDATE %i SET meta_value = NULL WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
    'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
    'DELETE FROM %i WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
    'DELETE FROM %i WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
);
$tokens = array_values( array_filter( token_get_all( $source ), static fn( $t ) => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
$text = static fn( $t ) => is_array( $t ) ? $t[1] : $t;
$sequence = static function ( $index, $parts ) use ( $tokens, $text ) { foreach ( $parts as $offset => $part ) { if ( ! isset( $tokens[ $index + $offset ] ) || $text( $tokens[ $index + $offset ] ) !== $part ) { return false; } } return true; };
$seen = array(); $calls = array_fill_keys( array( 'prepare', 'query', 'get_results', 'insert' ), 0 );
foreach ( $tokens as $index => $token ) {
    if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) { continue; }
    if ( isset( $tokens[ $index - 1 ] ) && is_array( $tokens[ $index - 1 ] ) && T_GLOBAL === $tokens[ $index - 1 ][0] && ';' === ( $tokens[ $index + 1 ] ?? null ) ) { continue; }
    if ( ! $sequence( $index + 1, array( '->' ) ) || ! isset( $tokens[ $index + 2 ] ) || ! is_array( $tokens[ $index + 2 ] ) || T_STRING !== $tokens[ $index + 2 ][0] ) { $fail( 'database alias or dynamic member access' ); }
    $member = $tokens[ $index + 2 ][1];
    if ( in_array( $member, array( 'termmeta', 'last_error' ), true ) ) { if ( '=' === ( $tokens[ $index + 3 ] ?? null ) ) { $fail( 'database property assignment' ); } continue; }
    if ( ! array_key_exists( $member, $calls ) || '(' !== ( $tokens[ $index + 3 ] ?? null ) ) { $fail( 'unexpected database member' ); }
    ++$calls[ $member ];
    if ( 'prepare' === $member ) {
        $literal = $tokens[ $index + 4 ] ?? null;
        if ( ! is_array( $literal ) || T_CONSTANT_ENCAPSED_STRING !== $literal[0] || "'" !== $literal[1][0] || ',' !== ( $tokens[ $index + 5 ] ?? null ) ) { $fail( 'non-literal or concatenated SQL template' ); }
        $query = substr( $literal[1], 1, -1 );
        if ( ! in_array( $query, $expected, true ) || ! $sequence( $index + 6, array( '$wpdb', '->', 'termmeta', ',' ) ) ) { $fail( 'unexpected SQL template or table identity' ); }
        $seen[] = $query;
    } elseif ( in_array( $member, array( 'query', 'get_results' ), true ) ) {
        if ( ! $sequence( $index + 4, array( '$wpdb', '->', 'prepare', '(' ) ) ) { $fail( 'unprepared query/read input' ); }
    } elseif ( ! $sequence( $index + 4, array( '$wpdb', '->', 'termmeta', ',', 'array', '(' ) ) ) { $fail( 'non-fixed restoration table/row shape' ); }
}
sort( $seen ); sort( $expected );
if ( $seen !== $expected || $calls !== array( 'prepare' => 8, 'query' => 6, 'get_results' => 2, 'insert' => 1 ) ) { $fail( 'persistence template/call inventory changed' ); }
echo "PASS: term-meta literal SQL and database-member confinement.\n";
