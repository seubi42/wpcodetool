<?php
/**
 * SimpleXLSXGen (adapted)
 *
 * A lightweight XLSX generator used by the MonsterInsights export module.
 *
 * This file is based on (and inspired by) SimpleXLSXGen by Sergey Shuchkin:
 * - Source: https://raw.githubusercontent.com/shuchkin/simplexlsxgen/refs/heads/master/src/SimpleXLSXGen.php
 *
 * Notes:
 * - This is a trimmed implementation for MonsterInsights needs (single/multi-sheet, basic strings/numbers).
 * - It requires the PHP Zip extension (ZipArchive).
 *
 * @package MonsterInsights
 * @subpackage Pro/Export
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MonsterInsights_SimpleXLSXGen
 *
 * Minimal XLSX generator (OOXML) for exporting tabular data.
 *
 * Supported:
 * - Single or multiple worksheets
 * - Strings + numbers (auto-detected)
 * - Shared strings across all sheets
 * - Basic escaping
 */
final class MonsterInsights_SimpleXLSXGen {

	/**
	 * Build XLSX binary contents for a single worksheet.
	 *
	 * @param array  $rows       2D array of rows (each row is a list of scalar values).
	 * @param string $sheet_name Optional. Worksheet name. Default 'Report'.
	 * @return string|WP_Error XLSX binary string or WP_Error.
	 */
	public static function from_rows( $rows, $sheet_name = 'Report' ) {
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return new WP_Error( 'xlsx_invalid_rows', __( 'Invalid XLSX data.', 'google-analytics-premium' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'xlsx_zip_missing', __( 'ZipArchive PHP extension is not available on this server.', 'google-analytics-premium' ) );
		}

		$sheet_name = self::sanitize_sheet_name( $sheet_name );

		// Shared strings table.
		$shared_strings = array();
		$shared_index   = array();

		// Build sheet XML rows.
		$sheet_rows_xml = array();
		$row_idx        = 0;

		foreach ( $rows as $row ) {
			$row_idx++;
			if ( ! is_array( $row ) ) {
				$row = array( (string) $row );
			}

			$cells_xml = array();
			$col_idx   = 0;

			foreach ( $row as $value ) {
				$col_idx++;
				$cell_ref = self::coord2cell( $col_idx, $row_idx );

				if ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				}

				// Numeric detection: allow ints/floats or numeric strings.
				if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) ) {
					$cells_xml[] = '<c r="' . $cell_ref . '"><v>' . self::esc( (string) $value ) . '</v></c>';
					continue;
				}

				if ( is_null( $value ) ) {
					$value = '';
				}

				$value = (string) $value;

				// Shared string.
				if ( isset( $shared_index[ $value ] ) ) {
					$s = $shared_index[ $value ];
				} else {
					$s                          = count( $shared_strings );
					$shared_index[ $value ]     = $s;
					$shared_strings[ $s ]       = $value;
				}

				$cells_xml[] = '<c r="' . $cell_ref . '" t="s"><v>' . $s . '</v></c>';
			}

			$sheet_rows_xml[] = '<row r="' . $row_idx . '">' . implode( '', $cells_xml ) . '</row>';
		}

		$worksheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetData>' . implode( '', $sheet_rows_xml ) . '</sheetData>'
			. '</worksheet>';

		$shared_xml = self::build_shared_strings_xml( $shared_strings );

		$workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>'
			. '<sheet name="' . self::esc( $sheet_name ) . '" sheetId="1" r:id="rId1"/>'
			. '</sheets>'
			. '</workbook>';

		$workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
			. '</Relationships>';

		$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '</Types>';

		// Build ZIP in memory.
		$tmp = self::create_temp_file( 'mi-export-xlsx' );
		if ( ! $tmp ) {
			return new WP_Error( 'xlsx_tmp_error', __( 'Failed to create temporary file for XLSX.', 'google-analytics-premium' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'xlsx_zip_open_error', __( 'Failed to create XLSX archive.', 'google-analytics-premium' ) );
		}

		$zip->addFromString( '[Content_Types].xml', $content_types );
		$zip->addFromString( '_rels/.rels', $root_rels );
		$zip->addFromString( 'xl/workbook.xml', $workbook_xml );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $workbook_rels );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $worksheet_xml );
		$zip->addFromString( 'xl/sharedStrings.xml', $shared_xml );
		$zip->close();

		$xlsx = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $xlsx || '' === $xlsx ) {
			return new WP_Error( 'xlsx_read_error', __( 'Failed to build XLSX file.', 'google-analytics-premium' ) );
		}

		return $xlsx;
	}

	/**
	 * Build XLSX binary contents for multiple worksheets.
	 *
	 * @since 9.0.0
	 *
	 * @param array $sheets Array of sheets, each with 'name' (string) and 'rows' (2D array) keys.
	 * @return string|WP_Error XLSX binary string or WP_Error.
	 */
	public static function from_multi_sheets( $sheets ) {
		if ( ! is_array( $sheets ) || empty( $sheets ) ) {
			return new WP_Error( 'xlsx_invalid_sheets', __( 'Invalid XLSX sheets data.', 'google-analytics-premium' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'xlsx_zip_missing', __( 'ZipArchive PHP extension is not available on this server.', 'google-analytics-premium' ) );
		}

		// Shared strings table (shared across all sheets).
		$shared_strings = array();
		$shared_index   = array();

		// Build worksheets data.
		$worksheets_xml   = array();
		$sheets_xml_parts = array();
		$rels_parts       = array();
		$content_parts    = array();
		$sheet_idx        = 0;

		foreach ( $sheets as $sheet ) {
			$sheet_idx++;

			$sheet_name = isset( $sheet['name'] ) ? self::sanitize_sheet_name( $sheet['name'] ) : 'Sheet' . $sheet_idx;
			$rows       = isset( $sheet['rows'] ) && is_array( $sheet['rows'] ) ? $sheet['rows'] : array();

			// Skip empty sheets.
			if ( empty( $rows ) ) {
				continue;
			}

			// Build sheet XML rows.
			$sheet_rows_xml = array();
			$row_idx        = 0;

			foreach ( $rows as $row ) {
				$row_idx++;
				if ( ! is_array( $row ) ) {
					$row = array( (string) $row );
				}

				$cells_xml = array();
				$col_idx   = 0;

				foreach ( $row as $value ) {
					$col_idx++;
					$cell_ref = self::coord2cell( $col_idx, $row_idx );

					if ( is_bool( $value ) ) {
						$value = $value ? 'true' : 'false';
					}

					// Numeric detection: allow ints/floats or numeric strings.
					if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) ) {
						$cells_xml[] = '<c r="' . $cell_ref . '"><v>' . self::esc( (string) $value ) . '</v></c>';
						continue;
					}

					if ( is_null( $value ) ) {
						$value = '';
					}

					$value = (string) $value;

					// Shared string (shared across all sheets).
					if ( isset( $shared_index[ $value ] ) ) {
						$s = $shared_index[ $value ];
					} else {
						$s                      = count( $shared_strings );
						$shared_index[ $value ] = $s;
						$shared_strings[ $s ]   = $value;
					}

					$cells_xml[] = '<c r="' . $cell_ref . '" t="s"><v>' . $s . '</v></c>';
				}

				$sheet_rows_xml[] = '<row r="' . $row_idx . '">' . implode( '', $cells_xml ) . '</row>';
			}

			$worksheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
				. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
				. '<sheetData>' . implode( '', $sheet_rows_xml ) . '</sheetData>'
				. '</worksheet>';

			$worksheets_xml[ $sheet_idx ] = $worksheet_xml;

			// Build sheet reference for workbook.xml.
			$sheets_xml_parts[] = '<sheet name="' . self::esc( $sheet_name ) . '" sheetId="' . $sheet_idx . '" r:id="rId' . $sheet_idx . '"/>';

			// Build relationship for workbook.xml.rels.
			$rels_parts[] = '<Relationship Id="rId' . $sheet_idx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheet_idx . '.xml"/>';

			// Build content type override for [Content_Types].xml.
			$content_parts[] = '<Override PartName="/xl/worksheets/sheet' . $sheet_idx . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}

		// If no valid sheets were created, return error.
		if ( empty( $worksheets_xml ) ) {
			return new WP_Error( 'xlsx_no_data', __( 'No data available for XLSX export.', 'google-analytics-premium' ) );
		}

		$shared_xml = self::build_shared_strings_xml( $shared_strings );

		// Shared strings relationship ID comes after all sheets.
		$shared_strings_rid = count( $worksheets_xml ) + 1;
		$rels_parts[]       = '<Relationship Id="rId' . $shared_strings_rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

		$workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>'
			. implode( '', $sheets_xml_parts )
			. '</sheets>'
			. '</workbook>';

		$workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. implode( '', $rels_parts )
			. '</Relationships>';

		$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. implode( '', $content_parts )
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '</Types>';

		// Build ZIP in memory.
		$tmp = self::create_temp_file( 'mi-export-xlsx-multi' );
		if ( ! $tmp ) {
			return new WP_Error( 'xlsx_tmp_error', __( 'Failed to create temporary file for XLSX.', 'google-analytics-premium' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'xlsx_zip_open_error', __( 'Failed to create XLSX archive.', 'google-analytics-premium' ) );
		}

		$zip->addFromString( '[Content_Types].xml', $content_types );
		$zip->addFromString( '_rels/.rels', $root_rels );
		$zip->addFromString( 'xl/workbook.xml', $workbook_xml );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $workbook_rels );
		$zip->addFromString( 'xl/sharedStrings.xml', $shared_xml );

		// Add each worksheet.
		foreach ( $worksheets_xml as $idx => $xml ) {
			$zip->addFromString( 'xl/worksheets/sheet' . $idx . '.xml', $xml );
		}

		$zip->close();

		$xlsx = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $xlsx || '' === $xlsx ) {
			return new WP_Error( 'xlsx_read_error', __( 'Failed to build XLSX file.', 'google-analytics-premium' ) );
		}

		return $xlsx;
	}

	/**
	 * Build sharedStrings.xml.
	 *
	 * @param array $strings Shared strings list.
	 * @return string XML.
	 */
	private static function build_shared_strings_xml( $strings ) {
		$count = is_array( $strings ) ? count( $strings ) : 0;
		$items = array();

		if ( $count ) {
			foreach ( $strings as $s ) {
				$items[] = '<si><t xml:space="preserve">' . self::esc( (string) $s ) . '</t></si>';
			}
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">'
			. implode( '', $items )
			. '</sst>';
	}

	/**
	 * Escape string for XML.
	 *
	 * @param string $str String to escape.
	 * @return string
	 */
	private static function esc( $str ) {
		return str_replace(
			array( '&', '<', '>', '"', "\x00", "\x03", "\x0B" ),
			array( '&amp;', '&lt;', '&gt;', '&quot;', '', '', '' ),
			$str
		);
	}

	/**
	 * Sanitize worksheet name.
	 *
	 * @param string $name Proposed name.
	 * @return string Sanitized.
	 */
	private static function sanitize_sheet_name( $name ) {
		$name = (string) $name;
		$name = preg_replace( '/[\x00-\x1F\x7F:\[\]\*\/\\\\\?]/', '', $name );
		$name = trim( $name );
		if ( '' === $name ) {
			$name = 'Report';
		}
		// Excel sheet name max is 31 chars.
		if ( strlen( $name ) > 31 ) {
			$name = substr( $name, 0, 31 );
		}
		return $name;
	}

	/**
	 * Convert numeric coordinates to Excel cell reference (A1, B2, ...).
	 *
	 * @param int $x 1-based column.
	 * @param int $y 1-based row.
	 * @return string
	 */
	private static function coord2cell( $x, $y ) {
		$x = (int) $x;
		$y = (int) $y;
		$c = '';
		for ( $i = $x - 1; $i >= 0; $i = ( (int) ( $i / 26 ) ) - 1 ) {
			$c = chr( 65 + ( $i % 26 ) ) . $c;
		}
		return $c . $y;
	}

	/**
	 * Create a temporary file with fallback.
	 *
	 * Uses wp_tempnam() if available, otherwise falls back to PHP's tempnam().
	 *
	 * @param string $prefix Prefix for the temp file name.
	 * @return string|false Path to temp file or false on failure.
	 */
	private static function create_temp_file( $prefix = 'mi-xlsx' ) {
		// Try WordPress function first.
		if ( function_exists( 'wp_tempnam' ) ) {
			$tmp = wp_tempnam( $prefix );
			if ( $tmp ) {
				return $tmp;
			}
		}

		// Fallback to PHP's native tempnam().
		$tmp_dir = function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : '/tmp';
		$tmp     = tempnam( $tmp_dir, $prefix );

		return $tmp;
	}
}
