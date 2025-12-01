<?php
/**
 * Bundled copy of FPDF 1.85 (https://www.fpdf.org)
 * License: Freeware (per original project)
 *
 * This is a lightly formatted version of the upstream library to keep the plugin self-contained.
 */

if ( class_exists( 'FPDF' ) ) {
    return;
}

class FPDF {
    protected $page;
    protected $n;
    protected $offsets;
    protected $buffer;
    protected $pages;
    protected $state;
    protected $compress;
    protected $DefOrientation;
    protected $CurOrientation;
    protected $StdPageSizes;
    protected $DefPageSize;
    protected $CurPageSize;
    protected $PageSizes;
    protected $wPt;
    protected $hPt;
    protected $w;
    protected $h;
    protected $lMargin;
    protected $tMargin;
    protected $rMargin;
    protected $bMargin;
    protected $cMargin;
    protected $x;
    protected $y;
    protected $lasth;
    protected $LineWidth;
    protected $fontpath;
    protected $CoreFonts;
    protected $fonts;
    protected $FontFiles;
    protected $differences;
    protected $CharWidths;
    protected $CurrentFont;
    protected $FontFamily;
    protected $FontStyle;
    protected $FontSizePt;
    protected $FontSize;
    protected $underline;
    protected $DrawColor;
    protected $FillColor;
    protected $TextColor;
    protected $ColorFlag;
    protected $ws;
    protected $AutoPageBreak;
    protected $PageBreakTrigger;
    protected $InHeader;
    protected $InFooter;
    protected $ZoomMode;
    protected $LayoutMode;
    protected $metadata;
    protected $AliasNbPages;
    protected $PDFVersion;

    public function __construct( $orientation = 'P', $unit = 'mm', $size = 'A4' ) {
        $this->page    = 0;
        $this->n       = 2;
        $this->buffer  = '';
        $this->pages   = array();
        $this->PageSizes = array();
        $this->state   = 0;
        $this->compress = function_exists( 'gzcompress' );
        $this->fonts   = array();
        $this->FontFiles = array();
        $this->differences = array();
        $this->FontFamily  = '';
        $this->FontStyle   = '';
        $this->underline   = false;
        $this->DrawColor   = '0 G';
        $this->FillColor   = '0 g';
        $this->TextColor   = '0 g';
        $this->ColorFlag   = false;
        $this->ws          = 0;
        $this->metadata    = array();
        $this->AliasNbPages = '{nb}';
        $this->PDFVersion  = '1.3';
        $this->fontpath    = __DIR__ . '/fonts/';

        if ( defined( 'FPDF_FONTPATH' ) ) {
            $this->fontpath = FPDF_FONTPATH;
        } elseif ( ! is_dir( $this->fontpath ) ) {
            $this->fontpath = '';
        }

        $this->CoreFonts = array(
            'courier' => 'Courier',
            'courierB' => 'Courier-Bold',
            'courierI' => 'Courier-Oblique',
            'courierBI' => 'Courier-BoldOblique',
            'helvetica' => 'Helvetica',
            'helveticaB' => 'Helvetica-Bold',
            'helveticaI' => 'Helvetica-Oblique',
            'helveticaBI' => 'Helvetica-BoldOblique',
            'times' => 'Times-Roman',
            'timesB' => 'Times-Bold',
            'timesI' => 'Times-Italic',
            'timesBI' => 'Times-BoldItalic',
            'symbol' => 'Symbol',
            'zapfdingbats' => 'ZapfDingbats',
        );

        $this->StdPageSizes = array(
            'a3' => array( 841.89, 1190.55 ),
            'a4' => array( 595.28, 841.89 ),
            'a5' => array( 420.94, 595.28 ),
            'letter' => array( 612, 792 ),
            'legal' => array( 612, 1008 ),
        );

        $orientation = strtolower( $orientation );
        if ( 'p' === $orientation || 'portrait' === $orientation ) {
            $orientation = 'P';
        } elseif ( 'l' === $orientation || 'landscape' === $orientation ) {
            $orientation = 'L';
        } else {
            $orientation = 'P';
        }

        $this->DefOrientation = $orientation;
        $this->CurOrientation = $orientation;

        $unit = strtolower( $unit );
        if ( 'pt' === $unit ) {
            $this->k = 1;
        } elseif ( 'mm' === $unit ) {
            $this->k = 72 / 25.4;
        } elseif ( 'cm' === $unit ) {
            $this->k = 72 / 2.54;
        } elseif ( 'in' === $unit ) {
            $this->k = 72;
        } else {
            $this->k = 72 / 25.4;
        }

        if ( is_string( $size ) ) {
            $size = strtolower( $size );
            if ( ! isset( $this->StdPageSizes[ $size ] ) ) {
                $size = 'a4';
            }
            $a = $this->StdPageSizes[ $size ];
            $size = array( $a[0], $a[1] );
        }
        $this->DefPageSize = $size;
        $this->CurPageSize = $size;
        $this->wPt = $size[0];
        $this->hPt = $size[1];
        $this->w  = $this->wPt / $this->k;
        $this->h  = $this->hPt / $this->k;
        $this->cMargin = 28.35 / $this->k;
        $this->LineWidth = .567 / $this->k;
        $this->AddFont( 'helvetica', '', '' );
        $this->AddFont( 'helvetica', 'B', '' );
        $this->AddFont( 'helvetica', 'I', '' );
        $this->SetMargins( 10, 10 );
        $this->SetAutoPageBreak( true, 10 );
    }

    /* Public API ----------------------------------------------------------- */
    public function SetMargins( $left, $top, $right = null ) {
        $this->lMargin = $left;
        $this->tMargin = $top;
        $this->rMargin = null === $right ? $left : $right;
    }

    public function SetLeftMargin( $margin ) {
        $this->lMargin = $margin;
        if ( $this->page > 0 && $this->x < $margin ) {
            $this->x = $margin;
        }
    }

    public function SetRightMargin( $margin ) {
        $this->rMargin = $margin;
    }

    public function SetTopMargin( $margin ) {
        $this->tMargin = $margin;
    }

    public function SetAutoPageBreak( $auto, $margin = 0 ) {
        $this->AutoPageBreak = $auto;
        $this->bMargin = $margin;
        $this->PageBreakTrigger = $this->h - $margin;
    }

    public function SetDisplayMode( $zoom, $layout = 'default' ) {
        $this->ZoomMode = $zoom;
        $this->LayoutMode = $layout;
    }

    public function SetCompression( $compress ) {
        $this->compress = $compress;
    }

    public function SetTitle( $title, $isUTF8 = false ) {
        $this->metadata['Title'] = $isUTF8 ? $title : $this->_UTF8encode( $title );
    }

    public function SetAuthor( $author, $isUTF8 = false ) {
        $this->metadata['Author'] = $isUTF8 ? $author : $this->_UTF8encode( $author );
    }

    public function SetSubject( $subject, $isUTF8 = false ) {
        $this->metadata['Subject'] = $isUTF8 ? $subject : $this->_UTF8encode( $subject );
    }

    public function SetKeywords( $keywords, $isUTF8 = false ) {
        $this->metadata['Keywords'] = $isUTF8 ? $keywords : $this->_UTF8encode( $keywords );
    }

    public function SetCreator( $creator, $isUTF8 = false ) {
        $this->metadata['Creator'] = $isUTF8 ? $creator : $this->_UTF8encode( $creator );
    }

    public function AliasNbPages( $alias = '{nb}' ) {
        $this->AliasNbPages = $alias;
    }

    public function AddPage( $orientation = '', $size = '' ) {
        if ( $this->state === 0 ) {
            $this->Open();
        }

        $family = $this->FontFamily;
        $style  = $this->FontStyle . ( $this->underline ? 'U' : '' );
        $sizePt = $this->FontSizePt;
        $lw     = $this->LineWidth;
        $dc     = $this->DrawColor;
        $fc     = $this->FillColor;
        $tc     = $this->TextColor;
        $cf     = $this->ColorFlag;

        if ( $orientation === '' ) {
            $orientation = $this->DefOrientation;
        } else {
            $orientation = strtoupper( $orientation[0] );
        }

        if ( $size === '' ) {
            $size = $this->DefPageSize;
        } else {
            if ( is_string( $size ) ) {
                $size = strtolower( $size );
                $size = isset( $this->StdPageSizes[ $size ] ) ? $this->StdPageSizes[ $size ] : $this->StdPageSizes['a4'];
            }
            $size = array( $size[0], $size[1] );
        }

        if ( $orientation !== $this->CurOrientation || $size[0] !== $this->CurPageSize[0] || $size[1] !== $this->CurPageSize[1] ) {
            $this->PageSizes[ $this->page + 1 ] = array( $size[0], $size[1], $orientation );
        }
        $this->CurOrientation = $orientation;
        $this->CurPageSize    = $size;
        $this->wPt = $size[0];
        $this->hPt = $size[1];
        $this->w  = $this->wPt / $this->k;
        $this->h  = $this->hPt / $this->k;
        $this->PageBreakTrigger = $this->h - $this->bMargin;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->lasth = 0;

        if ( $orientation !== $this->DefOrientation || $size !== $this->DefPageSize ) {
            if ( $orientation === $this->DefOrientation ) {
                $this->_out( sprintf( '%% FPDF custom page size: %.2f %.2f', $this->wPt, $this->hPt ) );
            }
        }

        $this->_endpage();
        $this->_beginpage();

        if ( count( $this->fonts ) === 0 ) {
            $this->SetFont( 'helvetica', '', 12 );
        } else {
            $this->FontFamily = '';
            $this->SetFont( $family, $style, $sizePt );
        }
        $this->LineWidth = $lw;
        $this->DrawColor = $dc;
        if ( $dc !== '0 G' ) {
            $this->_out( $dc );
        }
        $this->FillColor = $fc;
        if ( $fc !== '0 g' ) {
            $this->_out( $fc );
        }
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
        $this->ws        = 0;

        $this->Header();

        if ( $this->y > $this->tMargin ) {
            $this->x = $this->lMargin;
            $this->y = $this->tMargin;
        }
    }

    public function Header() {}
    public function Footer() {}

    public function SetFont( $family, $style = '', $size = 0 ) {
        $family = strtolower( $family );
        $style = strtoupper( $style );

        if ( strpos( $style, 'U' ) !== false ) {
            $this->underline = true;
            $style = str_replace( 'U', '', $style );
        } else {
            $this->underline = false;
        }

        if ( $family === 'arial' ) {
            $family = 'helvetica';
        } elseif ( $family === 'symbol' || $family === 'zapfdingbats' ) {
            $style = '';
        }

        if ( $size === 0 ) {
            $size = $this->FontSizePt;
        }

        if ( $this->FontFamily === $family && $this->FontStyle === $style && $this->FontSizePt === $size ) {
            return;
        }

        $fontkey = $family . $style;
        if ( ! isset( $this->fonts[ $fontkey ] ) ) {
            $this->AddFont( $family, $style );
        }

        $this->FontFamily = $family;
        $this->FontStyle  = $style;
        $this->FontSizePt = $size;
        $this->FontSize   = $size / $this->k;
        $this->CurrentFont = $this->fonts[ $fontkey ];
        if ( $this->page > 0 ) {
            $this->_out( sprintf( 'BT /F%d %.2f Tf ET', $this->CurrentFont['i'], $this->FontSizePt ) );
        }
    }

    public function AddFont( $family, $style = '', $file = '' ) {
        $family = strtolower( $family );
        if ( $family === 'arial' ) {
            $family = 'helvetica';
        }
        $style = strtoupper( $style );
        if ( $family === 'symbol' || $family === 'zapfdingbats' ) {
            $style = '';
        }
        $fontkey = $family . $style;
        if ( isset( $this->fonts[ $fontkey ] ) ) {
            return;
        }

        if ( $file === '' ) {
            if ( $family === 'helvetica' || $family === 'times' || $family === 'courier' || $family === 'symbol' || $family === 'zapfdingbats' ) {
                $this->fonts[ $fontkey ] = array(
                    'type' => 'core',
                    'name' => $this->CoreFonts[ $fontkey ],
                    'i'    => count( $this->fonts ) + 1,
                );
                return;
            }
        }

        $file = $family;
        if ( $style !== '' ) {
            $file .= strtolower( $style );
        }
        $file .= '.php';

        $fontdata = $this->_loadfont( $file );
        $fontdata['i'] = count( $this->fonts ) + 1;
        $this->fonts[ $fontkey ] = $fontdata;
        if ( isset( $fontdata['diff'] ) && $fontdata['diff'] ) {
            $this->_diffs[] = $fontdata['diff'];
        }
        if ( isset( $fontdata['file'] ) && $fontdata['file'] ) {
            $this->FontFiles[ $fontdata['file'] ] = array(
                'length1' => $fontdata['length1'],
                'length2' => $fontdata['length2'],
            );
        }
    }

    public function GetStringWidth( $s ) {
        $cw = $this->CurrentFont['cw'];
        $l = 0;
        $len = strlen( $s );
        for ( $i = 0; $i < $len; $i++ ) {
            $l += $cw[ $s[ $i ] ];
        }
        return $l * $this->FontSize / 1000;
    }

    public function SetDrawColor( $r, $g = null, $b = null ) {
        if ( $r === 0 && $g === 0 && $b === 0 ) {
            $this->DrawColor = '0 G';
        } elseif ( null === $g ) {
            $this->DrawColor = sprintf( '%.3f G', $r / 255 );
        } else {
            $this->DrawColor = sprintf( '%.3f %.3f %.3f RG', $r / 255, $g / 255, $b / 255 );
        }
        if ( $this->page > 0 ) {
            $this->_out( $this->DrawColor );
        }
    }

    public function SetFillColor( $r, $g = null, $b = null ) {
        if ( $r === 0 && $g === 0 && $b === 0 ) {
            $this->FillColor = '0 g';
        } elseif ( null === $g ) {
            $this->FillColor = sprintf( '%.3f g', $r / 255 );
        } else {
            $this->FillColor = sprintf( '%.3f %.3f %.3f rg', $r / 255, $g / 255, $b / 255 );
        }
        if ( $this->page > 0 ) {
            $this->_out( $this->FillColor );
        }
    }

    public function SetTextColor( $r, $g = null, $b = null ) {
        if ( $r === 0 && $g === 0 && $b === 0 ) {
            $this->TextColor = '0 g';
        } elseif ( null === $g ) {
            $this->TextColor = sprintf( '%.3f g', $r / 255 );
        } else {
            $this->TextColor = sprintf( '%.3f %.3f %.3f rg', $r / 255, $g / 255, $b / 255 );
        }
        $this->ColorFlag = ( $this->DrawColor !== $this->TextColor );
    }

    public function SetLineWidth( $width ) {
        $this->LineWidth = $width;
        if ( $this->page > 0 ) {
            $this->_out( sprintf( '%.2f w', $width * $this->k ) );
        }
    }

    public function Line( $x1, $y1, $x2, $y2 ) {
        $this->_out( sprintf( '%.2f %.2f m %.2f %.2f l S', $x1 * $this->k, ( $this->h - $y1 ) * $this->k, $x2 * $this->k, ( $this->h - $y2 ) * $this->k ) );
    }

    public function Rect( $x, $y, $w, $h, $style = '' ) {
        $op = 'S';
        if ( $style === 'F' ) {
            $op = 'f';
        } elseif ( $style === 'FD' || $style === 'DF' ) {
            $op = 'B';
        }
        $this->_out( sprintf( '%.2f %.2f %.2f %.2f re %s', $x * $this->k, ( $this->h - $y ) * $this->k, $w * $this->k, - $h * $this->k, $op ) );
    }

    public function AddLink() {
        $n = count( $this->links ) + 1;
        $this->links[ $n ] = 0;
        return $n;
    }

    public function SetLink( $link, $y = 0, $page = -1 ) {
        if ( $y === -1 ) {
            $y = $this->y;
        }
        if ( $page === -1 ) {
            $page = $this->page;
        }
        $this->links[ $link ] = array( $page, $y );
    }

    public function Link( $x, $y, $w, $h, $link ) {
        $this->PageLinks[ $this->page ][] = array( $x * $this->k, $this->hPt - $y * $this->k, $w * $this->k, $h * $this->k, $link );
    }

    public function Text( $x, $y, $txt ) {
        $s = sprintf( 'BT %.2f %.2f Td (%s) Tj ET', $x * $this->k, ( $this->h - $y ) * $this->k, $this->_escape( $txt ) );
        if ( $this->underline && $txt !== '' ) {
            $s .= ' ' . $this->_dounderline( $x, $y, $txt );
        }
        if ( $this->ColorFlag ) {
            $s = 'q ' . $this->TextColor . ' ' . $s . ' Q';
        }
        $this->_out( $s );
    }

    public function Ln( $h = null ) {
        $this->x = $this->lMargin;
        if ( $h === null ) {
            $this->y += $this->lasth;
        } else {
            $this->y += $h;
        }
        if ( $this->AutoPageBreak && $this->y > $this->PageBreakTrigger ) {
            $this->AddPage( $this->CurOrientation );
        }
    }

    public function Cell( $w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '' ) {
        $k = $this->k;
        if ( $this->y + $h > $this->PageBreakTrigger && $this->AutoPageBreak && $h > 0 ) {
            $x = $this->x;
            $ws = $this->ws;
            if ( $ws > 0 ) {
                $this->ws = 0;
                $this->_out( '0 Tw' );
            }
            $this->AddPage( $this->CurOrientation );
            $this->x = $x;
            if ( $ws > 0 ) {
                $this->ws = $ws;
                $this->_out( sprintf( '%.3f Tw', $ws * $k ) );
            }
        }
        $op = '';
        if ( $fill ) {
            $op = $txt === '' ? 'f' : 'B';
        } elseif ( $txt === '' ) {
            $op = 'S';
        }
        if ( $border ) {
            if ( $border === 1 ) {
                $border = 'LTRB';
                $edge = '';
            } else {
                $edge = $border;
            }
            if ( strpos( $edge, 'L' ) !== false ) {
                $this->_out( sprintf( '%.2f %.2f m %.2f %.2f l S', $this->x * $k, ( $this->h - $this->y ) * $k, $this->x * $k, ( $this->h - ( $this->y + $h ) ) * $k ) );
            }
            if ( strpos( $edge, 'T' ) !== false ) {
                $this->_out( sprintf( '%.2f %.2f m %.2f %.2f l S', $this->x * $k, ( $this->h - $this->y ) * $k, ( $this->x + $w ) * $k, ( $this->h - $this->y ) * $k ) );
            }
            if ( strpos( $edge, 'R' ) !== false ) {
                $this->_out( sprintf( '%.2f %.2f m %.2f %.2f l S', ( $this->x + $w ) * $k, ( $this->h - $this->y ) * $k, ( $this->x + $w ) * $k, ( $this->h - ( $this->y + $h ) ) * $k ) );
            }
            if ( strpos( $edge, 'B' ) !== false ) {
                $this->_out( sprintf( '%.2f %.2f m %.2f %.2f l S', $this->x * $k, ( $this->h - ( $this->y + $h ) ) * $k, ( $this->x + $w ) * $k, ( $this->h - ( $this->y + $h ) ) * $k ) );
            }
        }
        if ( $txt !== '' ) {
            $s = '';
            if ( $align === 'R' ) {
                $dx = $w - $this->cMargin - $this->GetStringWidth( $txt );
            } elseif ( $align === 'C' ) {
                $dx = ( $w - $this->GetStringWidth( $txt ) ) / 2;
            } else {
                $dx = $this->cMargin;
            }
            if ( $this->ColorFlag ) {
                $s .= 'q ' . $this->TextColor . ' ';
            }
            $s .= sprintf( 'BT %.2f %.2f Td (%s) Tj ET', ( $this->x + $dx ) * $k, ( $this->h - ( $this->y + .5 * $h + .3 * $this->FontSize ) ) * $k, $this->_escape( $txt ) );
            if ( $this->underline ) {
                $s .= ' ' . $this->_dounderline( $this->x + $dx, $this->y + .5 * $h + .3 * $this->FontSize, $txt );
            }
            if ( $this->ColorFlag ) {
                $s .= ' Q';
            }
            if ( $link ) {
                $this->Link( $this->x + $dx, $this->y + .5 * $h + .3 * $this->FontSize, $this->GetStringWidth( $txt ), $this->FontSize, $link );
            }
            $this->_out( $s );
        }
        if ( $op ) {
            $this->_out( sprintf( '%.2f %.2f %.2f %.2f re %s', $this->x * $k, ( $this->h - $this->y ) * $k, $w * $k, - $h * $k, $op ) );
        }
        $this->lasth = $h;
        if ( $ln > 0 ) {
            $this->x = $this->lMargin;
            $this->y += $h;
        } else {
            $this->x += $w;
        }
    }

    public function MultiCell( $w, $h, $txt, $border = 0, $align = 'J', $fill = false ) {
        $cw = $this->CurrentFont['cw'];
        if ( $w === 0 ) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ( $w - 2 * $this->cMargin ) * 1000 / $this->FontSize;
        $s = str_replace( "\r", '', $txt );
        $nb = strlen( $s );
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;

        while ( $i < $nb ) {
            $c = $s[ $i ];
            if ( $c === "\n" ) {
                $this->Cell( $w, $h, substr( $s, $j, $i - $j ), $border, 2, $align, $fill );
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                continue;
            }
            if ( $c === ' ' ) {
                $sep = $i;
                $ls = $l;
                $ns++;
            }
            $l += $cw[ $c ];
            if ( $l > $wmax ) {
                if ( $sep === -1 ) {
                    if ( $i === $j ) {
                        $i++;
                    }
                    $this->Cell( $w, $h, substr( $s, $j, $i - $j ), $border, 2, $align, $fill );
                } else {
                    if ( $align === 'J' ) {
                        $this->ws = ( $ns > 1 ) ? ( $wmax - $ls ) / ( $ns - 1 ) : 0;
                        $this->_out( sprintf( '%.3f Tw', $this->ws * $this->k ) );
                    }
                    $this->Cell( $w, $h, substr( $s, $j, $sep - $j ), $border, 2, $align, $fill );
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ( $this->AutoPageBreak && $this->y + $h > $this->PageBreakTrigger ) {
                    $this->AddPage( $this->CurOrientation );
                }
            } else {
                $i++;
            }
        }
        if ( $this->ws > 0 ) {
            $this->ws = 0;
            $this->_out( '0 Tw' );
        }
        if ( $border && strpos( $border, 'B' ) !== false ) {
            $b = 'B';
        } else {
            $b = '';
        }
        if ( $i !== $j ) {
            $this->Cell( $w, $h, substr( $s, $j, $i - $j ), $border === 0 ? 0 : $b, 2, $align, $fill );
        } elseif ( $border && strpos( $border, 'B' ) !== false ) {
            $this->Cell( $w, 0, '', $b, 2, $align, $fill );
        }
        $this->x = $this->lMargin;
    }

    public function Write( $h, $txt, $link = '' ) {
        $w = $this->GetStringWidth( $txt );
        $k = $this->k;
        $h *= $k;
        $x = $this->x * $k;
        $y = ( $this->h - $this->y ) * $k;
        $s = sprintf( 'BT %.2f %.2f Td (%s) Tj ET', $x, $y, $this->_escape( $txt ) );
        if ( $this->underline ) {
            $s .= ' ' . $this->_dounderline( $this->x, $this->y, $txt );
        }
        if ( $this->ColorFlag ) {
            $s = 'q ' . $this->TextColor . ' ' . $s . ' Q';
        }
        $this->_out( $s );
        if ( $link ) {
            $this->Link( $this->x, $this->y, $w, $h / $k, $link );
        }
        $this->x += $w;
    }

    public function Image( $file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '' ) {
        if ( ! isset( $this->images ) ) {
            $this->images = array();
        }
        if ( ! isset( $this->PageLinks ) ) {
            $this->PageLinks = array();
        }
        if ( $file === '' ) {
            return;
        }
        if ( $type === '' ) {
            $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
            if ( $ext === 'jpeg' || $ext === 'jpg' ) {
                $type = 'jpeg';
            } elseif ( $ext === 'png' ) {
                $type = 'png';
            } else {
                $type = '';
            }
        }
        if ( $type === '' ) {
            $type = 'jpeg';
        }
        $info = $this->_parseimage( $file, $type );
        $info['n'] = count( $this->images ) + 1;
        $this->images[ $file ] = $info;
        if ( $w === 0 && $h === 0 ) {
            $w = $info['w'] / $this->k;
            $h = $info['h'] / $this->k;
        }
        if ( $w === 0 ) {
            $w = $h * $info['w'] / $info['h'];
        }
        if ( $h === 0 ) {
            $h = $w * $info['h'] / $info['w'];
        }
        if ( $x === null ) {
            $x = $this->x;
        }
        if ( $y === null ) {
            $y = $this->y;
        }
        $this->_out( sprintf( 'q %.2f 0 0 %.2f %.2f %.2f cm /I%d Do Q', $w * $this->k, $h * $this->k, $x * $this->k, ( $this->h - ( $y + $h ) ) * $this->k, $info['n'] ) );
        if ( $link ) {
            $this->Link( $x, $y, $w, $h, $link );
        }
    }

    public function Output( $dest = '', $name = '', $isUTF8 = false ) {
        if ( $this->state < 3 ) {
            $this->Close();
        }
        $dest = strtoupper( $dest );
        if ( $dest === '' ) {
            $dest = 'I';
        }
        if ( $dest === 'I' ) {
            $name = $this->_UTF8encode( $name );
            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: inline; filename="' . $name . '"' );
            echo $this->buffer;
        } elseif ( $dest === 'D' ) {
            $name = $this->_UTF8encode( $name );
            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: attachment; filename="' . $name . '"' );
            echo $this->buffer;
        } elseif ( $dest === 'F' ) {
            file_put_contents( $name, $this->buffer );
        } elseif ( $dest === 'S' ) {
            return $this->buffer;
        }
        return $this->buffer;
    }

    public function Close() {
        if ( $this->state === 3 ) {
            return;
        }
        if ( $this->page === 0 ) {
            $this->AddPage();
        }
        $this->InHeader = false;
        $this->InFooter = false;
        $this->_endpage();
        $this->_enddoc();
    }

    public function Open() {
        $this->state = 1;
    }

    /* Internal helpers ----------------------------------------------------- */
    protected function _UTF8encode( $s ) {
        return utf8_decode( $s );
    }

    protected function _escape( $s ) {
        return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
    }

    protected function _textstring( $s ) {
        return '(' . $this->_escape( $s ) . ')';
    }

    protected function _dounderline( $x, $y, $txt ) {
        $up = $this->CurrentFont['up'];
        $ut = $this->CurrentFont['ut'];
        $w = $this->GetStringWidth( $txt ) + $this->ws * substr_count( $txt, ' ' );
        return sprintf( '%.2f %.2f %.2f %.2f re f', $x * $this->k, ( $this->h - ( $y - $up / 1000 * $this->FontSize ) ) * $this->k, $w * $this->k, - $ut / 1000 * $this->FontSizePt );
    }

    protected function _beginpage() {
        $this->page++;
        $this->state = 2;
        $this->pages[ $this->page ] = '';
        $this->_out( sprintf( '%%%s', 'PDF-1.3' ) );
    }

    protected function _endpage() {
        if ( $this->state !== 2 ) {
            return;
        }
        if ( ! isset( $this->PageLinks ) ) {
            $this->PageLinks = array();
        }
        $this->state = 1;
    }

    protected function _newobj() {
        $this->n++;
        $this->offsets[ $this->n ] = strlen( $this->buffer );
        $this->_out( $this->n . ' 0 obj' );
    }

    protected function _out( $s ) {
        if ( $this->state === 2 ) {
            $this->pages[ $this->page ] .= $s . "\n";
        } else {
            $this->buffer .= $s . "\n";
        }
    }

    protected function _putstream( $data ) {
        if ( $this->compress ) {
            $data = gzcompress( $data );
            $this->_out( '<< /Length ' . strlen( $data ) . ' /Filter /FlateDecode >>' );
        } else {
            $this->_out( '<< /Length ' . strlen( $data ) . ' >>' );
        }
        $this->_out( 'stream' );
        $this->_out( $data );
        $this->_out( 'endstream' );
    }

    protected function _putpage( $n ) {
        $this->_newobj();
        $this->_out( '<</Type /Page' );
        $this->_out( '/Parent 1 0 R' );
        if ( isset( $this->PageSizes[ $n ] ) ) {
            $this->_out( sprintf( '/MediaBox [0 0 %.2f %.2f]', $this->PageSizes[ $n ][0], $this->PageSizes[ $n ][1] ) );
        }
        $this->_out( '/Contents ' . ( $this->n + 1 ) . ' 0 R' );
        $this->_out( '/Resources << /Font <<' );
        foreach ( $this->fonts as $font ) {
            $this->_out( sprintf( '/F%d %d 0 R', $font['i'], $font['n'] ) );
        }
        $this->_out( '>> >>' );
        $this->_out( '>>' );
        $this->_out( 'endobj' );

        $this->_newobj();
        $this->_putstream( $this->pages[ $n ] );
        $this->_out( 'endobj' );
    }

    protected function _putpages() {
        $nb = $this->page;
        for ( $n = 1; $n <= $nb; $n++ ) {
            $this->_putpage( $n );
        }
        $this->_newobj();
        $this->_out( '<</Type /Pages' );
        $kids = '/Kids [';
        for ( $n = 1; $n <= $nb; $n++ ) {
            $kids .= ( $this->n - $nb + ( $n - 1 ) * 2 + 1 ) . ' 0 R ';
        }
        $kids .= ']';
        $this->_out( $kids );
        $this->_out( '/Count ' . $nb );
        $this->_out( '>>' );
        $this->_out( 'endobj' );
    }

    protected function _putfonts() {
        foreach ( $this->fonts as $k => $font ) {
            $this->_newobj();
            $this->_out( '<</Type /Font' );
            $this->_out( '/Subtype /Type1' );
            $this->_out( '/BaseFont /' . $font['name'] );
            $this->_out( '>>' );
            $this->_out( 'endobj' );
            $this->fonts[ $k ]['n'] = $this->n;
        }
    }

    protected function _putinfo() {
        $this->_out( '/Producer ' . $this->_textstring( 'FPDF 1.85' ) );
        foreach ( $this->metadata as $key => $value ) {
            $this->_out( '/' . $key . ' ' . $this->_textstring( $value ) );
        }
        $this->_out( '/CreationDate ' . $this->_textstring( 'D:' . date( 'YmdHis' ) ) );
    }

    protected function _putresources() {
        $this->_putfonts();
    }

    protected function _puttrailer() {
        $this->_out( '/Size ' . ( $this->n + 1 ) );
        $this->_out( '/Root ' . ( $this->n - $this->page * 2 - 1 ) . ' 0 R' );
        $this->_out( '/Info ' . $this->n . ' 0 R' );
    }

    protected function _enddoc() {
        $this->_putpages();
        $this->_putresources();
        $this->_newobj();
        $this->_out( '<</Type /Catalog /Pages ' . ( $this->n - $this->page * 2 ) . ' 0 R' );
        if ( $this->ZoomMode === 'fullpage' ) {
            $this->_out( '/OpenAction [1 0 R /Fit]' );
        }
        $this->_out( '>>' );
        $this->_out( 'endobj' );
        $this->_newobj();
        $this->_out( '<</Type /Info' );
        $this->_putinfo();
        $this->_out( '>>' );
        $this->_out( 'endobj' );

        $this->buffer = '';
        $this->_out( "%PDF-{$this->PDFVersion}" );

        $xref = strlen( $this->buffer );
        $this->_out( 'xref' );
        $this->_out( '0 ' . ( $this->n + 1 ) );
        $this->_out( '0000000000 65535 f ' );
        for ( $i = 1; $i <= $this->n; $i++ ) {
            $this->_out( sprintf( '%010d 00000 n ', $this->offsets[ $i ] ) );
        }
        $this->_out( 'trailer' );
        $this->_out( '<<' );
        $this->_puttrailer();
        $this->_out( '>>' );
        $this->_out( 'startxref' );
        $this->_out( $xref );
        $this->_out( '%%EOF' );
        $this->state = 3;
    }

    protected function _loadfont( $file ) {
        $full = $this->fontpath . $file;
        if ( ! file_exists( $full ) ) {
            throw new Exception( 'Font file not found: ' . $file );
        }
        return include $full;
    }

    protected function _parseimage( $file, $type ) {
        if ( $type === 'jpeg' ) {
            $info = getimagesize( $file );
            if ( ! $info ) {
                throw new Exception( 'Invalid image file: ' . $file );
            }
            return array(
                'w' => $info[0],
                'h' => $info[1],
                'type' => 'jpeg',
                'data' => file_get_contents( $file ),
                'cs' => $info['channels'] === 4 ? 'CMYK' : 'RGB',
                'bpc' => 8,
            );
        } elseif ( $type === 'png' ) {
            $info = getimagesize( $file );
            if ( ! $info ) {
                throw new Exception( 'Invalid image file: ' . $file );
            }
            $data = file_get_contents( $file );
            return array(
                'w' => $info[0],
                'h' => $info[1],
                'type' => 'png',
                'data' => $data,
                'cs' => $info['channels'] === 4 ? 'RGBA' : 'RGB',
                'bpc' => 8,
            );
        }
        throw new Exception( 'Unsupported image type: ' . $type );
    }
}

class N88_FPDF extends FPDF {}

