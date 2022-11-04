<?php
/**
 * iCalcreator, the PHP class package managing iCal (rfc2445/rfc5445) calendar information.
 *
 * This file is a part of iCalcreator.
 *
 * @author    Kjell-Inge Gustafsson, kigkonsult <ical@kigkonsult.se>
 * @copyright 2007-2022 Kjell-Inge Gustafsson, kigkonsult, All rights reserved
 * @link      https://kigkonsult.se
 * @license   Subject matter of licence is the software iCalcreator.
 *            The above copyright, link, package and version notices,
 *            this licence notice and the invariant [rfc5545] PRODID result use
 *            as implemented and invoked in iCalcreator shall be included in
 *            all copies or substantial portions of the iCalcreator.
 *
 *            iCalcreator is free software: you can redistribute it and/or modify
 *            it under the terms of the GNU Lesser General Public License as
 *            published by the Free Software Foundation, either version 3 of
 *            the License, or (at your option) any later version.
 *
 *            iCalcreator is distributed in the hope that it will be useful,
 *            but WITHOUT ANY WARRANTY; without even the implied warranty of
 *            MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *            GNU Lesser General Public License for more details.
 *
 *            You should have received a copy of the GNU Lesser General Public License
 *            along with iCalcreator. If not, see <https://www.gnu.org/licenses/>.
*/
declare( strict_types = 1 );
namespace Kigkonsult\Icalcreator\Util;

use InvalidArgumentException;
use Kigkonsult\Icalcreator\Vcalendar;

use function array_reverse;
use function array_shift;
use function ctype_lower;
use function ctype_upper;
use function explode;
use function gmdate;
use function implode;
use function in_array;
use function sprintf;
use function strlen;
use function ucfirst;

/**
 * iCalcreator vCard support class
 *
 * @since 2.40.11 2022-01-15
 */
class IcalvCardFactory
{
    /**
     * @var string[]
     */
    private static array $VCARDVERSIONS = [ 2 => '2.1', 3 => '3.0', 4 => '4.0' ];

    /**
     * Convert single ATTENDEE, CONTACT or ORGANIZER (in email format) to vCard 2.1, 3,0 or 4.0
     *
     * Returns vCard/true or if directory (if set) or file write is invalid, false
     *
     * @param string $email
     * @param null|string $version   vCard version (default 2.1)
     * @return string
     * @throws InvalidArgumentException
     * @since  2.27.8 - 2019-03-18
     */
    public static function iCal2vCard( string $email, ? string $version = null ) : string
    {
        static $FMTFN      = "FN:%s\r\n";
        static $FMTEMAIL   = "EMAIL:%s\r\n";
        static $BEGINVCARD = "BEGIN:VCARD\r\n";
        static $FMTVERSION = "VERSION:%s\r\n";
        static $FMTPRODID  = "PRODID:-//kigkonsult.se %s\r\n";
        static $FMTREV     = "REV:%s\r\n";
        static $YMDTHISZ   = 'Ymd\THis\Z';
        static $ENDVCARD   = "END:VCARD\r\n";
        if( empty( $version )) {
            $version = self::$VCARDVERSIONS[2];
        }
        else {
            self::assertVcardVersion( $version );
        }
        CalAddressFactory::assertCalAddress( $email );
        /* prepare vCard name */
        $names   = self::splitNameInNameparts(
            CalAddressFactory::extractNamepartFromEmail( $email )
        );
        /* create vCard */
        $vCard   = $BEGINVCARD;
        $vCard  .= sprintf( $FMTVERSION, $version );
        $vCard  .= sprintf( $FMTPRODID, ICALCREATOR_VERSION );
        $vCard  .= self::getVcardN( $names, $version );
        $vCard  .= sprintf( $FMTFN, implode( Util::$SP1, $names ));
        $vCard  .= sprintf( $FMTEMAIL, CalAddressFactory::removeMailtoPrefix( $email ));
        $vCard  .= sprintf( $FMTREV, gmdate( $YMDTHISZ ));
        $vCard  .= $ENDVCARD;
        return $vCard;
    }

    /**
     * Convert ATTENDEEs, CONTACTs and ORGANIZERs (in email format) to vCard 2.1 or 4.0
     *
     * Skips ATTENDEEs, CONTACTs and ORGANIZERs not in email format
     * Force invoke of Vcalendar::participants2Attendees()
     *     Participant::calendaraddress, $inclParam=true also from Participant::contacts (both opt)
     *
     * @param Vcalendar   $calendar    iCalcreator Vcalendar instance
     * @param null|string $version     vCard version (default 2.1)
     * @param null|bool   $inclParam
     * @param null|int    $count       on return, count of hits
     * @return string   vCards
     * @since  2.41.4 - 2022-01-23
     */
    public static function iCal2vCards(
        Vcalendar $calendar,
        ? string $version= null,
        ? bool $inclParam = false,
        ? int & $count = 0
    ) : string
    {
        $calendar->participants2Attendees();
        $hits   = ( true === $inclParam )
            ? CalAddressFactory::getCalAdressesAllFromProperty( $calendar )
            : CalAddressFactory::getCalAddresses( $calendar ); // not from params, value only
        $output = Util::$SP0;
        $count  = 0;
        foreach( $hits as $email ) {
            try {
                $res = self::iCal2vCard( $email, $version );
            }
            catch( InvalidArgumentException ) {
                continue;
            }
            ++$count;
            $output .= $res;
        }
        return $output;
    }

    /**
     * Assert vCard version
     *
     * @param string $version
     * @return void
     * @throws InvalidArgumentException
     * @since  2.27.8 - 2019-03-18
     */
    private static function assertVcardVersion( string $version ) : void
    {
        static $ERRMSG1 = 'Invalid version %s';
        if( ! in_array( $version, self::$VCARDVERSIONS, true ) ) {
            throw new InvalidArgumentException( sprintf( $ERRMSG1, $version ));
        }
    }

    /**
     * Split string name into array nameParts
     *
     * @param string $name
     * @return string[]
     * @since  2.27.8 - 2019-03-17
     */
    private static function splitNameInNameparts( string $name ) : array
    {
        switch( true ) {
            case ( ctype_upper( $name ) || ctype_lower( $name )) :
                $nameParts = [ $name ];
                break;
            case ( str_contains( $name, Util::$DOT )) :
                $nameParts = explode( Util::$DOT, $name );
                foreach( $nameParts as $k => $part ) {
                    $nameParts[$k] = ucfirst( $part );
                }
                break;
            default : // split camelCase
                $nameParts = [$name[0]];
                $k         = 0;
                $x         = 1;
                $len       = strlen( $name );
                while( $x < $len ) {
                    if( ctype_upper( $name[$x] )) {
                        ++$k;
                        $nameParts[$k] = Util::$SP0;
                    }
                    $nameParts[$k] .= $name[$x];
                    $x++;
                } // end while
                break;
        } // end switch
        return $nameParts;
    }

    /**
     * Return formatted vCard name
     *
     * @param string[] $names
     * @param string $version
     * @return string
     * @since  2.27.8 - 2019-03-18
     */
    private static function getVcardN( array $names, string $version ) : string
    {
        static $FMTN = 'N:%s';
        $name   = array_reverse( $names );
        $vCardN = sprintf( $FMTN, array_shift( $name ));
        $scCnt  = 0;
        while( null !== ( $part = array_shift( $name ))) {
            if(( self::$VCARDVERSIONS[4] !== $version ) || ( 4 > $scCnt )) {
                ++$scCnt;
            }
            $vCardN .= Util::$SEMIC . $part;
        } // end while
        while(( self::$VCARDVERSIONS[4] === $version ) && ( 4 > $scCnt )) {
            $vCardN .= Util::$SEMIC;
            ++$scCnt;
        } // end while
        return $vCardN . Util::$CRLF;
    }
}
