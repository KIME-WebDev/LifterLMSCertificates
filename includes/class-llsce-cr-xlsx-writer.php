<?php
/**
 * A small, dependency-free XLSX writer.
 *
 * Writes a single worksheet with inline strings, real numbers, real Excel
 * date-times, column widths, a frozen header row, and an optional Excel
 * table (with autofilter and a built-in table style). It streams the sheet
 * body to a temp file so large exports do not have to be held in memory.
 *
 * @package LLSCE_Certificate_Report
 */

defined( 'ABSPATH' ) || exit;

class LLSCE_CR_Xlsx_Writer {

	const TYPE_STRING   = 'string';
	const TYPE_NUMBER   = 'number';
	const TYPE_DATETIME = 'datetime';

	/** Seconds between the Unix epoch and Excel's 1899-12-30 epoch. */
	const EXCEL_EPOCH_OFFSET = 2209161600;

	const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/** @var string */
	private $sheet_name;

	/** @var string[] */
	private $headers;

	/** @var string[] One of the TYPE_* constants per column. */
	private $types;

	/** @var array<int, float> Zero-based column index => width in characters. */
	private $widths;

	/** @var string|null */
	private $table_name;

	/** @var string */
	private $table_style;

	/**
	 * @param string       $sheet_name  Worksheet name (sanitised to Excel's rules).
	 * @param string[]     $headers     Column headers, in order.
	 * @param string[]     $types       Column types, same order as $headers.
	 * @param array        $widths      Zero-based column index => width. Missing columns use Excel's default.
	 * @param string|null  $table_name  When set, the data is wrapped in an Excel table of this name.
	 * @param string       $table_style A built-in Excel table style name.
	 */
	public function __construct( string $sheet_name, array $headers, array $types, array $widths = array(), ?string $table_name = null, string $table_style = 'TableStyleLight9' ) {
		$this->sheet_name  = self::sanitise_sheet_name( $sheet_name );
		$this->headers     = array_values( $headers );
		$this->types       = array_values( $types );
		$this->widths      = $widths;
		$this->table_name  = $table_name ? self::sanitise_table_name( $table_name ) : null;
		$this->table_style = $table_style;
	}

	/**
	 * Write the workbook to $path.
	 *
	 * @param iterable<array> $rows Each row is a list of scalar values in header order.
	 * @return int Number of data rows written.
	 * @throws RuntimeException When the file cannot be produced.
	 */
	public function write( iterable $rows, string $path ): int {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'The PHP zip extension is required to build an .xlsx file.' );
		}

		$body_path  = tempnam( sys_get_temp_dir(), 'llsce-body-' );
		$sheet_path = tempnam( sys_get_temp_dir(), 'llsce-sheet-' );
		if ( false === $body_path || false === $sheet_path ) {
			throw new RuntimeException( 'Could not create a temporary file for the worksheet.' );
		}

		try {
			$row_count = $this->write_sheet_body( $rows, $body_path );
			$last_row  = $row_count + 1;
			$last_col  = self::column_letter( count( $this->headers ) - 1 );
			$ref       = 'A1:' . $last_col . $last_row;
			$has_table = null !== $this->table_name && $row_count > 0;

			$this->assemble_sheet( $body_path, $sheet_path, $ref, $has_table );

			$zip = new ZipArchive();
			if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Could not open the .xlsx file for writing.' );
			}

			$zip->addFromString( '[Content_Types].xml', $this->content_types_xml( $has_table ) );
			$zip->addFromString( '_rels/.rels', $this->root_rels_xml() );
			$zip->addFromString( 'xl/workbook.xml', $this->workbook_xml() );
			$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels_xml() );
			$zip->addFromString( 'xl/styles.xml', $this->styles_xml() );
			$zip->addFile( $sheet_path, 'xl/worksheets/sheet1.xml' );

			if ( $has_table ) {
				$zip->addFromString( 'xl/worksheets/_rels/sheet1.xml.rels', $this->sheet_rels_xml() );
				$zip->addFromString( 'xl/tables/table1.xml', $this->table_xml( $ref ) );
			}

			if ( true !== $zip->close() ) {
				throw new RuntimeException( 'Could not finalise the .xlsx file.' );
			}

			return $row_count;
		} finally {
			foreach ( array( $body_path, $sheet_path ) as $temp ) {
				if ( file_exists( $temp ) ) {
					unlink( $temp );
				}
			}
		}
	}

	/**
	 * Wrap the streamed body in the worksheet head and tail without loading it into memory.
	 */
	private function assemble_sheet( string $body_path, string $sheet_path, string $ref, bool $has_table ): void {
		$out = fopen( $sheet_path, 'wb' );
		$in  = fopen( $body_path, 'rb' );
		if ( false === $out || false === $in ) {
			throw new RuntimeException( 'Could not assemble the worksheet.' );
		}

		fwrite( $out, $this->sheet_head_xml( $ref ) );
		stream_copy_to_stream( $in, $out );
		fwrite( $out, $this->sheet_tail_xml( $has_table ) );

		fclose( $in );
		fclose( $out );
	}

	/**
	 * Stream the header row and every data row into $body_path.
	 */
	private function write_sheet_body( iterable $rows, string $body_path ): int {
		$handle = fopen( $body_path, 'wb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Could not write the worksheet body.' );
		}

		fwrite( $handle, '<sheetData>' );
		fwrite( $handle, $this->row_xml( 1, $this->headers, array_fill( 0, count( $this->headers ), self::TYPE_STRING ) ) );

		$row_number = 1;
		foreach ( $rows as $row ) {
			++$row_number;
			fwrite( $handle, $this->row_xml( $row_number, array_values( $row ), $this->types ) );
		}

		fwrite( $handle, '</sheetData>' );
		fclose( $handle );

		return $row_number - 1;
	}

	private function row_xml( int $row_number, array $values, array $types ): string {
		$cells = '';
		foreach ( $values as $index => $value ) {
			$ref    = self::column_letter( $index ) . $row_number;
			$cells .= $this->cell_xml( $ref, $value, $types[ $index ] ?? self::TYPE_STRING );
		}

		return '<row r="' . $row_number . '">' . $cells . '</row>';
	}

	private function cell_xml( string $ref, $value, string $type ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( self::TYPE_NUMBER === $type && is_numeric( $value ) ) {
			return '<c r="' . $ref . '"><v>' . self::number_text( $value ) . '</v></c>';
		}

		if ( self::TYPE_DATETIME === $type ) {
			$serial = self::excel_serial( (string) $value );
			if ( null !== $serial ) {
				return '<c r="' . $ref . '" s="1"><v>' . self::number_text( $serial ) . '</v></c>';
			}
		}

		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml_text( (string) $value ) . '</t></is></c>';
	}

	/**
	 * Convert a MySQL-style date-time string into an Excel serial number.
	 * The string is treated as wall-clock time (no timezone shifting).
	 */
	public static function excel_serial( string $datetime ): ?float {
		$datetime = trim( $datetime );
		if ( '' === $datetime || 0 === strpos( $datetime, '0000-00-00' ) ) {
			return null;
		}

		try {
			$moment = new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $exception ) {
			return null;
		}

		return ( $moment->getTimestamp() + self::EXCEL_EPOCH_OFFSET ) / 86400;
	}

	private static function number_text( $value ): string {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( ltrim( $value, '-' ) ) ) ) {
			return (string) $value;
		}

		return rtrim( rtrim( sprintf( '%.10F', (float) $value ), '0' ), '.' );
	}

	/**
	 * Escape text for XML and drop characters XML 1.0 forbids.
	 */
	public static function xml_text( string $text ): string {
		if ( ! preg_match( '//u', $text ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}

		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );

		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	public static function column_letter( int $index ): string {
		$letters = '';
		do {
			$letters = chr( 65 + ( $index % 26 ) ) . $letters;
			$index   = intdiv( $index, 26 ) - 1;
		} while ( $index >= 0 );

		return $letters;
	}

	private static function sanitise_sheet_name( string $name ): string {
		$name = (string) preg_replace( '/[\[\]\*\?\/\\\\:]/', ' ', $name );
		$name = trim( $name );
		if ( '' === $name ) {
			$name = 'Sheet1';
		}

		return mb_substr( $name, 0, 31 );
	}

	private static function sanitise_table_name( string $name ): string {
		$name = (string) preg_replace( '/[^A-Za-z0-9_]/', '_', $name );
		if ( '' === $name || ! preg_match( '/^[A-Za-z_]/', $name ) ) {
			$name = 'Table_' . $name;
		}

		return $name;
	}

	private function content_types_xml( bool $has_table ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
		$xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
		$xml .= '<Default Extension="xml" ContentType="application/xml"/>';
		$xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
		$xml .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		$xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		if ( $has_table ) {
			$xml .= '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
		}
		$xml .= '</Types>';

		return $xml;
	}

	private function root_rels_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private function workbook_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
			. '<sheets><sheet name="' . self::xml_text( $this->sheet_name ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	private function workbook_rels_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	private function sheet_rels_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Style index 0 is the default; index 1 is the date-time format.
	 */
	private function styles_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="' . self::NS_MAIN . '">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="mm\-dd\-yy"/></numFmts>'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="2">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
			. '</styleSheet>';
	}

	private function sheet_head_xml( string $ref ): string {
		$cols = '';
		foreach ( $this->widths as $index => $width ) {
			$column = (int) $index + 1;
			$cols  .= '<col min="' . $column . '" max="' . $column . '" width="' . self::number_text( (float) $width ) . '" customWidth="1"/>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
			. '<dimension ref="' . $ref . '"/>'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. ( '' !== $cols ? '<cols>' . $cols . '</cols>' : '' );
	}

	private function sheet_tail_xml( bool $has_table ): string {
		return '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
			. ( $has_table ? '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>' : '' )
			. '</worksheet>';
	}

	private function table_xml( string $ref ): string {
		$columns = '';
		foreach ( $this->headers as $index => $header ) {
			$columns .= '<tableColumn id="' . ( $index + 1 ) . '" name="' . self::xml_text( $header ) . '"/>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<table xmlns="' . self::NS_MAIN . '" id="1" name="' . $this->table_name . '" displayName="' . $this->table_name . '" ref="' . $ref . '" totalsRowShown="0">'
			. '<autoFilter ref="' . $ref . '"/>'
			. '<tableColumns count="' . count( $this->headers ) . '">' . $columns . '</tableColumns>'
			. '<tableStyleInfo name="' . self::xml_text( $this->table_style ) . '" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>'
			. '</table>';
	}
}
