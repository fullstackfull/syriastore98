<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\Marketing\app\Models\ContactInfo;
use Workdo\Marketing\app\Models\Appoinment;
use Workdo\Marketing\app\Models\Service;
use Workdo\Marketing\app\Models\Testimonial;
use Workdo\Marketing\app\Models\Social;
use Workdo\Marketing\app\Models\CardProduct;
use Workdo\Marketing\app\Models\CardPayment;
use Workdo\Marketing\app\Models\Gallery;
use App\Models\User;
use Workdo\Marketing\app\Models\StoreHours as business_hours;


class Store extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $businessSlugData = null;
    private static $businessCurrentData = null;
    protected $fillable = [];

    public static function themeOne()
    {
        $arr = [];

        $arr = [
            'theme1' => [
                'color1-theme1' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme1/color1.png'),
                    'color' => '#F9D254',
                    'theme_name' => 'theme1-v1'
                ],
                'color2-theme1' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme1/color2.png'),
                    'color' => '#8ECAE6',
                    'theme_name' => 'theme1-v2'
                ],
                'color3-theme1' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme1/color3.png'),
                    'color' => '#FDF0D5',
                    'theme_name' => 'theme1-v3'
                ],
                'color4-theme1' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme1/color4.png'),
                    'color' => '#E9EDC9',
                    'theme_name' => 'theme1-v4'
                ],
            ],
            'theme2' => [
                'color1-theme2' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme2/color1.png'),
                    'color' => '#1840DA',
                    'theme_name' => 'theme2-v1'
                ],
                'color2-theme2' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme2/color2.png'),
                    'color' => '#8338EC',
                    'theme_name' => 'theme2-v2'
                ],
                'color3-theme2' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme2/color3.png'),
                    'color' => '#3A5A40',
                    'theme_name' => 'theme2-v3'
                ],
                'color4-theme2' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme2/color4.png'),
                    'color' => '#003049',
                    'theme_name' => 'theme2-v4'
                ],
            ],
            'theme3' => [
                'color1-theme3' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme3/color1.png'),
                    'color' => '#B89C87',
                    'theme_name' => 'theme3-v1'
                ],
                'color2-theme3' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme3/color2.png'),
                    'color' => '#344E41',
                    'theme_name' => 'theme3-v2'
                ],
                'color3-theme3' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme3/color3.png'),
                    'color' => '#778DA9',
                    'theme_name' => 'theme3-v3'
                ],
                'color4-theme3' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme3/color4.png'),
                    'color' => '#0D1B2A',
                    'theme_name' => 'theme3-v4'
                ],
            ],
            'theme4' => [
                'color1-theme4' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme4/color1.png'),
                    'color' => '#ECDACA',
                    'theme_name' => 'theme4-v1'
                ],
                'color2-theme4' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme4/color2.png'),
                    'color' => '#FFC8DD',
                    'theme_name' => 'theme4-v2'
                ],
                'color3-theme4' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme4/color3.png'),
                    'color' => '#E9EDC9',
                    'theme_name' => 'theme4-v3'
                ],
                'color4-theme4' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme4/color4.png'),
                    'color' => '#8ECAE6',
                    'theme_name' => 'theme4-v4'
                ],
            ],
            'theme5' => [
                'color1-theme5' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme5/color1.png'),
                    'color' => '#022A2E',
                    'theme_name' => 'theme5-v1'
                ],
                'color2-theme5' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme5/color2.png'),
                    'color' => '#1B263B',
                    'theme_name' => 'theme5-v2'
                ],
                'color3-theme5' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme5/color3.png'),
                    'color' => '#283618',
                    'theme_name' => 'theme5-v3'
                ],
                'color4-theme5' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme5/color4.png'),
                    'color' => '#000000',
                    'theme_name' => 'theme5-v4'
                ],
            ],
            'theme6' => [
                'color1-theme6' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme6/color1.png'),
                    'color' => '#ADD9FF',
                    'theme_name' => 'theme6-v1'
                ],
                'color2-theme6' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme6/color2.png'),
                    'color' => '#749D73',
                    'theme_name' => 'theme6-v2'
                ],
                'color3-theme6' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme6/color3.png'),
                    'color' => '#FFB703',
                    'theme_name' => 'theme6-v3'
                ],
                'color4-theme6' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme6/color4.png'),
                    'color' => '#DDA15E',
                    'theme_name' => 'theme6-v4'
                ],
            ],
            'theme7' => [
                'color1-theme7' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme7/color1.png'),
                    'color' => '#000',
                    'theme_name' => 'theme7-v1'
                ],
                'color2-theme7' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme7/color2.png'),
                    'color' => '#7E6455',
                    'theme_name' => 'theme7-v2'
                ],
                'color3-theme7' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme7/color3.png'),
                    'color' => '#14213D',
                    'theme_name' => 'theme7-v3'
                ],
                'color4-theme7' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme7/color4.png'),
                    'color' => '#283618',
                    'theme_name' => 'theme7-v4'
                ],
            ],
            'theme8' => [
                'color1-theme8' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme8/color1.png'),
                    'color' => '#242424',
                    'theme_name' => 'theme8-v1'
                ],
                'color2-theme8' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme8/color2.png'),
                    'color' => '#283618',
                    'theme_name' => 'theme8-v2'
                ],
                'color3-theme8' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme8/color3.png'),
                    'color' => '#1B263B',
                    'theme_name' => 'theme8-v3'
                ],
                'color4-theme8' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme8/color4.png'),
                    'color' => '#2477C5',
                    'theme_name' => 'theme8-v4'
                ],
            ],
            'theme9' => [
                'color1-theme9' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme9/color1.png'),
                    'color' => '#0248A3',
                    'theme_name' => 'theme9-v1'
                ],
                'color2-theme9' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme9/color2.png'),
                    'color' => '#3F02A3',
                    'theme_name' => 'theme9-v2'
                ],
                'color3-theme9' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme9/color3.png'),
                    'color' => '#A30280',
                    'theme_name' => 'theme9-v3'
                ],
                'color4-theme9' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme9/color4.png'),
                    'color' => '#8CA302',
                    'theme_name' => 'theme9-v4'
                ],
            ],
            'theme10' => [
                'color1-theme10' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme10/color1.png'),
                    'color' => '#000000',
                    'theme_name' => 'theme10-v1'
                ],
                'color2-theme10' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme10/color2.png'),
                    'color' => '#281616',
                    'theme_name' => 'theme10-v2'
                ],
                'color3-theme10' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme10/color3.png'),
                    'color' => '#162825',
                    'theme_name' => 'theme10-v3'
                ],
                'color4-theme10' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme10/color4.png'),
                    'color' => '#0D1D34',
                    'theme_name' => 'theme10-v4'
                ],
            ],
            'theme11' => [
                'color1-theme11' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme11/color1.png'),
                    'color' => '#000000',
                    'theme_name' => 'theme11-v1'
                ],
                'color2-theme11' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme11/color2.png'),
                    'color' => '#342F14',
                    'theme_name' => 'theme11-v2'
                ],
                'color3-theme11' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme11/color3.png'),
                    'color' => '#14342E',
                    'theme_name' => 'theme11-v3'
                ],
                'color4-theme11' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme11/color4.png'),
                    'color' => '#141F34',
                    'theme_name' => 'theme11-v4'
                ],
            ],

            'theme12' => [
                'color1-theme12' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme12/color1.png'),
                    'color' => '#FDD395',
                    'theme_name' => 'theme12-v1'
                ],
                'color2-theme12' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme12/color2.png'),
                    'color' => '#94D2BD',
                    'theme_name' => 'theme12-v2'
                ],
                'color3-theme12' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme12/color3.png'),
                    'color' => '#168AAD',
                    'theme_name' => 'theme12-v3'
                ],
                'color4-theme12' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme12/color4.png'),
                    'color' => '#A01A58',
                    'theme_name' => 'theme12-v4'
                ],
                'color5-theme12' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme12/color5.png'),
                    'color' => '#B5E48C',
                    'theme_name' => 'theme12-v5'
                ],
            ],
            'theme13' => [
                'color1-theme13' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme13/color1.png'),
                    'color' => 'linear-gradient(180deg, #ADE8F4 0%, #46B7CE 100%)',
                    'theme_name' => 'theme13-v1'
                ],
                'color2-theme13' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme13/color2.png'),
                    'color' => 'linear-gradient(180deg, #D9ED92 0%, #B5E48C 100%)',
                    'theme_name' => 'theme13-v2'
                ],
                'color3-theme13' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme13/color3.png'),
                    'color' => 'linear-gradient(180deg, #F7B801 0%, #F18701 100%)',
                    'theme_name' => 'theme13-v3'
                ],
                'color4-theme13' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme13/color4.png'),
                    'color' => 'linear-gradient(180deg, #94D2BD 0%, #0A9396 100%)',
                    'theme_name' => 'theme13-v4'
                ],
                'color5-theme13' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme13/color5.png'),
                    'color' => 'linear-gradient(180deg, #FF7900 0%, #FF5400 100%)',
                    'theme_name' => 'theme13-v5'
                ],
            ],

            'theme14' => [
                'color1-theme14' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme14/color1.png'),
                    'color' => '#99E2B4',
                    'theme_name' => 'theme14-v1'
                ],
                'color2-theme14' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme14/color2.png'),
                    'color' => '#F18701',
                    'theme_name' => 'theme14-v2'
                ],
                'color3-theme14' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme14/color3.png'),
                    'color' => '#34A0A4',
                    'theme_name' => 'theme14-v3'
                ],
                'color4-theme14' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme14/color4.png'),
                    'color' => '#7678ED',
                    'theme_name' => 'theme14-v4'
                ],
                'color5-theme14' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme14/color5.png'),
                    'color' => '#4EAAFF',
                    'theme_name' => 'theme14-v5'
                ],
            ],
            'theme15' => [
                'color1-theme15' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme15/color1.png'),
                    'color' => '#000000',
                    'theme_name' => 'theme15-v1'
                ],
                'color2-theme15' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme15/color2.png'),
                    'color' => '#858585;',
                    'theme_name' => 'theme15-v2'
                ],
                'color3-theme15' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme15/color3.png'),
                    'color' => '#005F73',
                    'theme_name' => 'theme15-v3'
                ],
                'color4-theme15' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme15/color4.png'),
                    'color' => '#723C70',
                    'theme_name' => 'theme15-v4'
                ],
                'color5-theme15' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme15/color5.png'),
                    'color' => '#60873A',
                    'theme_name' => 'theme15-v5'
                ],
            ],
            'theme16' => [
                'color1-theme16' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme16/color1.png'),
                    'color' => '#F05C35',
                    'theme_name' => 'theme16-v1'
                ],
                'color2-theme16' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme16/color2.png'),
                    'color' => '#0A9396',
                    'theme_name' => 'theme16-v2'
                ],
                'color3-theme16' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme16/color3.png'),
                    'color' => '#B5E48C',
                    'theme_name' => 'theme16-v3'
                ],
                'color4-theme16' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme16/color4.png'),
                    'color' => '#B7094C',
                    'theme_name' => 'theme16-v4'
                ],
                'color5-theme16' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme16/color5.png'),
                    'color' => '#7678ED',
                    'theme_name' => 'theme16-v5'
                ],
            ],
            'theme17' => [
                'color1-theme17' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme17/color1.png'),
                    'color' => '#52189C',
                    'theme_name' => 'theme17-v1'
                ],
                'color2-theme17' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme17/color2.png'),
                    'color' => '#FF9E00',
                    'theme_name' => 'theme17-v2'
                ],
                'color3-theme17' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme17/color3.png'),
                    'color' => '#CB997E',
                    'theme_name' => 'theme17-v3'
                ],
                'color4-theme17' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme17/color4.png'),
                    'color' => '#6B705C',
                    'theme_name' => 'theme17-v4'
                ],
                'color5-theme17' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme17/color5.png'),
                    'color' => '#76C893',
                    'theme_name' => 'theme17-v5'
                ],
            ],
            'theme18' => [
                'color1-theme18' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme18/color1.png'),
                    'color' => '#000000',
                    'theme_name' => 'theme18-v1'
                ],
                'color2-theme18' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme18/color2.png'),
                    'color' => '#455E89',
                    'theme_name' => 'theme18-v2'
                ],
                'color3-theme18' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme18/color3.png'),
                    'color' => '#3D348B',
                    'theme_name' => 'theme18-v3'
                ],
                'color4-theme18' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme18/color4.png'),
                    'color' => '#9B2226',
                    'theme_name' => 'theme18-v4'
                ],
                'color5-theme18' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme18/color5.png'),
                    'color' => '#52B69A',
                    'theme_name' => 'theme18-v5'
                ],
            ],
            'theme19' => [
                'color1-theme19' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme19/color1.png'),
                    'color' => 'linear-gradient(102.24deg, #936639 6.21%, #656D4A 99.29%)',
                    'theme_name' => 'theme19-v1'
                ],
                'color2-theme19' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme19/color2.png'),
                    'color' => 'linear-gradient(102.24deg, #723C70 6.21%, #2E6F95 99.29%)',
                    'theme_name' => 'theme19-v2'
                ],
                'color3-theme19' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme19/color3.png'),
                    'color' => 'linear-gradient(102.24deg, #005F73 6.21%, #0A9396 99.29%)',
                    'theme_name' => 'theme19-v3'
                ],
                'color4-theme19' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme19/color4.png'),
                    'color' => 'linear-gradient(102.24deg, #9B2226 6.21%, #BB3E03 99.29%)',
                    'theme_name' => 'theme19-v4'
                ],
                'color5-theme19' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme19/color5.png'),
                    'color' => 'linear-gradient(102.24deg, #76C893 6.21%, #99D98C 99.29%)',
                    'theme_name' => 'theme19-v5'
                ],
            ],
            'theme20' => [
                'color1-theme20' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme20/color1.png'),
                    'color' => '#FFD4E0',
                    'theme_name' => 'theme20-v1'
                ],
                'color2-theme20' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme20/color2.png'),
                    'color' => '#FFE8D6',
                    'theme_name' => 'theme20-v2'
                ],
                'color3-theme20' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme20/color3.png'),
                    'color' => '#B7B7A4',
                    'theme_name' => 'theme20-v3'
                ],
                'color4-theme20' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme20/color4.png'),
                    'color' => '#B5E48C',
                    'theme_name' => 'theme20-v4'
                ],
                'color5-theme20' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme20/color5.png'),
                    'color' => '#94D2BD',
                    'theme_name' => 'theme20-v5'
                ],
            ],
            'theme21' => [
                'color1-theme21' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme21/color1.png'),
                    'color' => '#F7762E',
                    'theme_name' => 'theme21-v1'
                ],
                'color2-theme21' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme21/color2.png'),
                    'color' => '#7678ED',
                    'theme_name' => 'theme21-v2'
                ],
                'color3-theme21' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme21/color3.png'),
                    'color' => '#99D98C',
                    'theme_name' => 'theme21-v3'
                ],
                'color4-theme21' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme21/color4.png'),
                    'color' => '#1A759F',
                    'theme_name' => 'theme21-v4'
                ],
                'color5-theme21' => [
                    'img_path' => asset('packages/workdo/Marketing/src/resources/assets/custom/card_theme/theme21/color5.png'),
                    'color' => '#6B705C',
                    'theme_name' => 'theme21-v5'
                ],
            ],



        ];

        return $arr;
    }
    public static function getBusinessBySlug($slug)
    {
        if (self::$businessSlugData == null) {
            $data = Store::where('slug', '=', $slug)->first();
            self::$businessSlugData = $data;
        }
        return self::$businessSlugData;
    }

   public static function card_cookie($slug)
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        $company_setting = getAdminAllSetting($store->created_by,$store->id);
        $data = $company_setting['gdpr_text'] ?? '';
        return $data;
    }
    public static function pixelSourceCode($platform, $pixelId)
    {
        // Facebook Pixel script
        if ($platform === 'facebook') {
            $script = "
				<script>
					!function(f,b,e,v,n,t,s)
					{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
					n.callMethod.apply(n,arguments):n.queue.push(arguments)};
					if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
					n.queue=[];t=b.createElement(e);t.async=!0;
					t.src=v;s=b.getElementsByTagName(e)[0];
					s.parentNode.insertBefore(t,s)}(window, document,'script',
					'https://connect.facebook.net/en_US/fbevents.js');
					fbq('init', '%s');
					fbq('track', 'PageView');
				</script>

				<noscript><img height='1' width='1' style='display:none' src='https://www.facebook.com/tr?id=%d&ev=PageView&noscript=1'/></noscript>
			";

            return sprintf($script, $pixelId, $pixelId);
        }
    }
    public static function getDefaultThemeOrder($themename)
    {
        $order = [];
        if ($themename == 'theme1') {
            $order = [
                'description' => '1',
                'contact_info' => '2',
                'bussiness_hour' => '3',
                'appointment' => '4',
                'service' => '5',
                'product' => '6',
                'gallery' => '7',
                'more' => '8',
                'testimonials' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme2') {
            $order = [
                'description' => '1',
                'service' => '2',
                'product' => '3',
                'contact_info' => '4',
                'bussiness_hour' => '5',
                'appointment' => '6',
                'gallery' => '7',
                'more' => '8',
                'testimonials' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme3') {
            $order = [
                'description' => '1',
                'gallery' => '2',
                'service' => '3',
                'bussiness_hour' => '4',
                'product' => '5',
                'contact_info' => '6',
                'appointment' => '7',
                'testimonials' => '8',
                'social' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme4') {
            $order = [
                'description' => '1',
                'gallery' => '2',
                'service' => '3',
                'product' => '4',
                'bussiness_hour' => '5',
                'contact_info' => '6',
                'appointment' => '7',
                'testimonials' => '8',
                'social' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme5') {
            $order = [
                'description' => '1',
                'social' => '2',
                'service' => '3',
                'product' => '4',
                'bussiness_hour' => '5',
                'gallery' => '6',
                'appointment' => '7',
                'testimonials' => '8',
                'contact_info' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme6') {
            $order = [
                'description' => '1',
                'contact_info' => '2',
                'service' => '3',
                'product' => '4',
                'bussiness_hour' => '5',
                'appointment' => '6',
                'gallery' => '7',
                'testimonials' => '8',
                'social' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme7') {
            $order = [
                'description' => '1',
                'gallery' => '2',
                'service' => '3',
                'product' => '4',
                'bussiness_hour' => '5',
                'appointment' => '6',
                'social' => '7',
                'contact_info' => '8',
                'testimonials' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme8') {
            $order = [
                'description' => '1',
                'social' => '2',
                'service' => '3',
                'product' => '4',
                'appointment' => '5',
                'bussiness_hour' => '6',
                'gallery' => '7',
                'contact_info' => '8',
                'testimonials' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme9') {
            $order = [
                'description' => '1',
                'social' => '2',
                'service' => '3',
                'product' => '4',
                'appointment' => '5',
                'bussiness_hour' => '6',
                'gallery' => '7',
                'contact_info' => '8',
                'testimonials' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme10') {
            $order = [
                'description' => '1',
                'appointment' => '2',
                'contact_info' => '3',
                'service' => '4',
                'product' => '5',
                'bussiness_hour' => '6',
                'gallery' => '7',
                'testimonials' => '8',
                'social' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme11') {
            $order = [
                'description' => '1',
                'contact_info' => '2',
                'gallery' => '3',
                'social' => '4',
                'service' => '5',
                'product' => '6',
                'bussiness_hour' => '7',
                'appointment' => '8',
                'testimonials' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme12') {
            $order = [
                'description' => '1',
                'contact_info' => '2',
                'bussiness_hour' => '3',
                'appointment' => '4',
                'service' => '5',
                'product' => '6',
                'gallery' => '7',
                'more' => '8',
                'testimonials' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',

            ];
        }
        if ($themename == 'theme13') {
            $order = [
                'description' => '1',
                'contact_info' => '2',
                'bussiness_hour' => '3',
                'appointment' => '4',
                'service' => '5',
                'product' => '6',
                'gallery' => '7',
                'more' => '8',
                'testimonials' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme14') {
            $order = [
                'description' => '1',
                'contact_info' => '2',
                'appointment' => '3',
                'testimonials' => '4',
                'bussiness_hour' => '5',
                'service' => '6',
                'product' => '7',
                'gallery' => '8',
                'more' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme15') {
            $order = [
                'more' => '1',
                'description' => '2',
                'social' => '3',
                'appointment' => '4',
                'service' => '5',
                'product' => '6',
                'gallery' => '7',
                'testimonials' => '8',
                'bussiness_hour' => '9',
                'contact_info' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme16') {
            $order = [
                'appointment' => '1',
                'service' => '2',
                'product' => '3',
                'gallery' => '4',
                'testimonials' => '5',
                'bussiness_hour' => '6',
                'contact_info' => '7',
                'more' => '8',
                'payment' => '9',
                'custom_html' => '10',
                'social' => '11',
            ];
        }
        if ($themename == 'theme17') {
            $order = [
                'description' => '1',
                'social' => '2',
                'contact_info' => '3',
                'appointment' => '4',
                'testimonials' => '5',
                'bussiness_hour' => '6',
                'service' => '7',
                'product' => '8',
                'gallery' => '9',
                'more' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme18') {
            $order = [
                'contact_info' => '1',
                'more' => '2',
                'description' => '3',
                'appointment' => '4',
                'testimonials' => '5',
                'bussiness_hour' => '6',
                'service' => '7',
                'product' => '8',
                'gallery' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme19') {
            $order = [
                'description' => '1',
                'contact_info' => '2',
                'more' => '3',
                'appointment' => '4',
                'service' => '5',
                'product' => '6',
                'gallery' => '7',
                'testimonials' => '8',
                'bussiness_hour' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme20') {
            $order = [
                'more' => '1',
                'service' => '2',
                'product' => '3',
                'scan_me' => '4',
                'gallery' => '5',
                'appointment' => '6',
                'contact_info' => '7',
                'testimonials' => '8',
                'bussiness_hour' => '9',
                'social' => '10',
                'payment' => '11',
                'custom_html' => '12',
            ];
        }
        if ($themename == 'theme21') {
            $order = [
                'contact_info' => '1',
                'description' => '2',
                'appointment' => '3',
                'service' => '4',
                'product' => '5',
                'gallery' => '6',
                'testimonials' => '7',
                'social' => '8',
                'more' => '9',
                'payment' => '10',
                'custom_html' => '11',
            ];
        }
        return $order;
    }


    public function getLanguage()
    {
        $user = User::where('id', getCurrentStore())->first();
        if ($user) {
            return $user->lang;
        } else {
            return 'en';
        }

    }

    public static function getvalueoffont($font)
    {
        $allfonts = Store::getfonts();
        if (!isset($allfonts[$font]) || empty($allfonts[$font])) {
            $allfonts[$font] = '';
        }

        return $allfonts[$font];
    }

    public static function isEnableBlock($block, $id)
    {


        if ($block == 'contact_info') {
            $block_data = ContactInfo::cardContactData($id);

            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;

            } else {
                $isenable = '0';
            }

        }
        if ($block == 'bussiness_hour') {
            $block_data = business_hours::cardBusinessHour($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }
        if ($block == 'appointment') {
            $block_data = appoinment::cardAppointmentData($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }
        if ($block == 'service') {
            $block_data = service::cardServiceData($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }
        if ($block == 'testimonials') {
            $block_data = testimonial::cardTestimonialData($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }
        if ($block == 'social') {
            $block_data = social::cardSocialData($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }
        if ($block == 'custom_html') {
            $customhtml = getAdminAllSetting();
            ;
            $custom_html = [];
            if (isset($customhtml["is_custom_html_enabled"])) {
                $block_data = $customhtml["is_custom_html_enabled"];
            } else {
                $block_data = null;
            }
            if ($block_data != NULL) {
                $isenable = $block_data;
            } else {
                $isenable = '0';
            }
        }
        //Gallery
        if ($block == 'gallery') {
            $block_data = Gallery::cardGalleryData($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }
        if ($block == 'product') {
            $block_data = CardProduct::cardProductData($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }
        //payment
        if ($block == 'payment') {
            $block_data = CardPayment::cardPaymentData($id);
            if ($block_data != NULL) {
                $isenable = $block_data->is_enabled;
            } else {
                $isenable = '0';
            }
        }

        return $isenable;
    }


    public static function pwa_business($slug)
    {

        $business = self::getBusinessBySlug($slug);
        try {

            $pwa_data = \File::get('uploads/theme_app/business_' . $store->id . '/manifest.json');

            $pwa_data = json_decode($pwa_data);
        } catch (\Throwable $th) {
            $pwa_data = [];
        }
        return $pwa_data;

    }

    public static $qr_type = [
        0 => 'Normal',
        2 => 'Text',
        4 => 'Image',
    ];

    public static function getfonts()
    {
        $fonts = [
            "Default" => [
                "link" => "https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap",
                "fontfamily" => "Inter,sans-serif",
            ],
            "Roboto" => [
                "link" => "https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap",
                "fontfamily" => "Roboto,sans-serif",
            ],
            "OpenSans" => [
                "link" => "https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700;1,800&display=swap",
                "fontfamily" => "Open Sans,sans-serif",
            ],
            "Montserrat" => [
                "link" => "https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap",
                "fontfamily" => "Montserrat,sans-serif",
            ],
            "Lato" => [
                "link" => "https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&display=swap",
                "fontfamily" => "Lato,sans-serif",
            ],
            "Raleway" => [
                "link" => "https://fonts.googleapis.com/css2?family=Raleway:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap",
                "fontfamily" => "Raleway,sans-serif",
            ],
            "PTSans" => [
                "link" => "https://fonts.googleapis.com/css2?family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap",
                "fontfamily" => "PT Sans,sans-serif",
            ],
            "WorkSans" => [
                "link" => "https://fonts.googleapis.com/css2?family=Work+Sans:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap",
                "fontfamily" => "Work Sans,sans-serif",
            ],
            "Merriweather" => [
                "link" => "https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,300;0,400;0,700;0,900;1,300;1,400;1,700;1,900&display=swap",
                "fontfamily" => "Merriweather,serif",
            ],
            "Prompt" => [
                "link" => "https://fonts.googleapis.com/css2?family=Prompt:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap",
                "fontfamily" => "Prompt,sans-serif",
            ],
            "ConcertOne" => [
                "link" => "https://fonts.googleapis.com/css2?family=Concert+One&display=swap",
                "fontfamily" => "Concert One,cursive",
            ],
        ];

        return $fonts;
    }


    public static function getCurrency()
    {
        $currencies = array(
            'code' =>
                array('code' => 'INR', 'name' => 'Indian', 'symbol' => '₹'),
            array('code' => 'AFN', 'name' => 'Afghani', 'symbol' => '؋'),
            array('code' => 'ANG', 'name' => 'Netherlands Antillian Guilder', 'symbol' => 'ƒ'),
            array('code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => '$'),
            array('code' => 'AWG', 'name' => 'Aruban Guilder', 'symbol' => 'ƒ'),
            array('code' => 'AZN', 'name' => 'Azerbaijanian Manat', 'symbol' => 'ман'),
            array('code' => 'BAM', 'name' => 'Convertible Marks', 'symbol' => 'KM'),
            array('code' => 'BBD', 'name' => 'Barbados Dollar', 'symbol' => '$'),
            array('code' => 'BGN', 'name' => 'Bulgarian Lev', 'symbol' => 'лв'),
            array('code' => 'BMD', 'name' => 'Bermudian Dollar', 'symbol' => '$'),
            array('code' => 'BND', 'name' => 'Brunei Dollar', 'symbol' => '$'),
            array('code' => 'BOB', 'name' => 'BOV Boliviano Mvdol', 'symbol' => '$b'),
            array('code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$'),
            array('code' => 'BSD', 'name' => 'Bahamian Dollar', 'symbol' => '$'),
            array('code' => 'BWP', 'name' => 'Pula', 'symbol' => 'P'),
            array('code' => 'BYR', 'name' => 'Belarussian Ruble', 'symbol' => 'p.'),
            array('code' => 'BZD', 'name' => 'Belize Dollar', 'symbol' => 'BZ$'),
            array('code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => '$'),
            array('code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF'),
            array('code' => 'CLP', 'name' => 'CLF Chilean Peso Unidades de fomento', 'symbol' => '$'),
            array('code' => 'CNY', 'name' => 'Yuan Renminbi', 'symbol' => '¥'),
            array('code' => 'COP', 'name' => 'COU Colombian Peso Unidad de Valor Real', 'symbol' => '$'),
            array('code' => 'CRC', 'name' => 'Costa Rican Colon', 'symbol' => '₡'),
            array('code' => 'CUP', 'name' => 'CUC Cuban Peso Peso Convertible', 'symbol' => '₱'),
            array('code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč'),
            array('code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr'),
            array('code' => 'DOP', 'name' => 'Dominican Peso', 'symbol' => 'RD$'),
            array('code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => '£'),
            array('code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'),
            array('code' => 'FJD', 'name' => 'Fiji Dollar', 'symbol' => '$'),
            array('code' => 'FKP', 'name' => 'Falkland Islands Pound', 'symbol' => '£'),
            array('code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£'),
            array('code' => 'GIP', 'name' => 'Gibraltar Pound', 'symbol' => '£'),
            array('code' => 'GTQ', 'name' => 'Quetzal', 'symbol' => 'Q'),
            array('code' => 'GYD', 'name' => 'Guyana Dollar', 'symbol' => '$'),
            array('code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => '$'),
            array('code' => 'HNL', 'name' => 'Lempira', 'symbol' => 'L'),
            array('code' => 'HRK', 'name' => 'Croatian Kuna', 'symbol' => 'kn'),
            array('code' => 'HUF', 'name' => 'Forint', 'symbol' => 'Ft'),
            array('code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp'),
            array('code' => 'ILS', 'name' => 'New Israeli Sheqel', 'symbol' => '₪'),
            array('code' => 'IRR', 'name' => 'Iranian Rial', 'symbol' => '﷼'),
            array('code' => 'ISK', 'name' => 'Iceland Krona', 'symbol' => 'kr'),
            array('code' => 'JMD', 'name' => 'Jamaican Dollar', 'symbol' => 'J$'),
            array('code' => 'JPY', 'name' => 'Yen', 'symbol' => '¥'),
            array('code' => 'KGS', 'name' => 'Som', 'symbol' => 'лв'),
            array('code' => 'KHR', 'name' => 'Riel', 'symbol' => '៛'),
            array('code' => 'KPW', 'name' => 'North Korean Won', 'symbol' => '₩'),
            array('code' => 'KRW', 'name' => 'Won', 'symbol' => '₩'),
            array('code' => 'KYD', 'name' => 'Cayman Islands Dollar', 'symbol' => '$'),
            array('code' => 'KZT', 'name' => 'Tenge', 'symbol' => 'лв'),
            array('code' => 'LAK', 'name' => 'Kip', 'symbol' => '₭'),
            array('code' => 'LBP', 'name' => 'Lebanese Pound', 'symbol' => '£'),
            array('code' => 'LKR', 'name' => 'Sri Lanka Rupee', 'symbol' => '₨'),
            array('code' => 'LRD', 'name' => 'Liberian Dollar', 'symbol' => '$'),
            array('code' => 'LTL', 'name' => 'Lithuanian Litas', 'symbol' => 'Lt'),
            array('code' => 'LVL', 'name' => 'Latvian Lats', 'symbol' => 'Ls'),
            array('code' => 'MKD', 'name' => 'Denar', 'symbol' => 'ден'),
            array('code' => 'MNT', 'name' => 'Tugrik', 'symbol' => '₮'),
            array('code' => 'MUR', 'name' => 'Mauritius Rupee', 'symbol' => '₨'),
            array('code' => 'MXN', 'name' => 'MXV Mexican Peso Mexican Unidad de Inversion (UDI)', 'symbol' => '$'),
            array('code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM'),
            array('code' => 'MZN', 'name' => 'Metical', 'symbol' => 'MT'),
            array('code' => 'NGN', 'name' => 'Naira', 'symbol' => '₦'),
            array('code' => 'NIO', 'name' => 'Cordoba Oro', 'symbol' => 'C$'),
            array('code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr'),
            array('code' => 'NPR', 'name' => 'Nepalese Rupee', 'symbol' => '₨'),
            array('code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => '$'),
            array('code' => 'OMR', 'name' => 'Rial Omani', 'symbol' => '﷼'),
            array('code' => 'PAB', 'name' => 'USD Balboa US Dollar', 'symbol' => 'B/.'),
            array('code' => 'PEN', 'name' => 'Nuevo Sol', 'symbol' => 'S/.'),
            array('code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => 'Php'),
            array('code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => '₨'),
            array('code' => 'PLN', 'name' => 'Zloty', 'symbol' => 'zł'),
            array('code' => 'PYG', 'name' => 'Guarani', 'symbol' => 'Gs'),
            array('code' => 'QAR', 'name' => 'Qatari Rial', 'symbol' => '﷼'),
            array('code' => 'RON', 'name' => 'New Leu', 'symbol' => 'lei'),
            array('code' => 'RSD', 'name' => 'Serbian Dinar', 'symbol' => 'Дин.'),
            array('code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => 'руб'),
            array('code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼'),
            array('code' => 'SBD', 'name' => 'Solomon Islands Dollar', 'symbol' => '$'),
            array('code' => 'SCR', 'name' => 'Seychelles Rupee', 'symbol' => '₨'),
            array('code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr'),
            array('code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => '$'),
            array('code' => 'SHP', 'name' => 'Saint Helena Pound', 'symbol' => '£'),
            array('code' => 'SOS', 'name' => 'Somali Shilling', 'symbol' => 'S'),
            array('code' => 'SRD', 'name' => 'Surinam Dollar', 'symbol' => '$'),
            array('code' => 'SVC', 'name' => 'USD El Salvador Colon US Dollar', 'symbol' => '$'),
            array('code' => 'SYP', 'name' => 'Syrian Pound', 'symbol' => '£'),
            array('code' => 'THB', 'name' => 'Baht', 'symbol' => '฿'),
            array('code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => 'TL'),
            array('code' => 'TTD', 'name' => 'Trinidad and Tobago Dollar', 'symbol' => 'TT$'),
            array('code' => 'TWD', 'name' => 'New Taiwan Dollar', 'symbol' => 'NT$'),
            array('code' => 'UAH', 'name' => 'Hryvnia', 'symbol' => '₴'),
            array('code' => 'USD', 'name' => 'United States Dollar', 'symbol' => '$'),
            array('code' => 'UYU', 'name' => 'UYI Peso Uruguayo Uruguay Peso en Unidades Indexadas', 'symbol' => '$U'),
            array('code' => 'UZS', 'name' => 'Uzbekistan Sum', 'symbol' => 'лв'),
            array('code' => 'VEF', 'name' => 'Bolivar Fuerte', 'symbol' => 'Bs'),
            array('code' => 'VND', 'name' => 'Dong', 'symbol' => '₫'),
            array('code' => 'XCD', 'name' => 'East Caribbean Dollar', 'symbol' => '$'),
            array('code' => 'YER', 'name' => 'Yemeni Rial', 'symbol' => '﷼'),
            array('code' => 'ZAR', 'name' => 'Rand', 'symbol' => 'R'),
        );
        return $currencies;
    }
    public static function GetDeviceType($user_agent)
    {
        $mobile_regex = '/(?:phone|windows\s+phone|ipod|blackberry|(?:android|bb\d+|meego|silk|googlebot) .+? mobile|palm|windows\s+ce|opera mini|avantgo|mobilesafari|docomo)/i';
        $tablet_regex = '/(?:ipad|playbook|(?:android|bb\d+|meego|silk)(?! .+? mobile))/i';
        if (preg_match_all($mobile_regex, $user_agent)) {
            return 'mobile';
        } else {
            if (preg_match_all($tablet_regex, $user_agent)) {
                return 'tablet';
            } else {
                return 'desktop';
            }
        }
    }



}
