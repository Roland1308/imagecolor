<?php

namespace App\Http\Controllers;

use App\FileUpload;
use League\ColorExtractor\Color;
use League\ColorExtractor\ColorExtractor;
use League\ColorExtractor\Palette;

use Illuminate\Http\Request;
use Imagick;

class ImageController extends Controller
{
    public function store(Request $request)
    //Receives the image and stores it in public/images folder and the name into the database
    {
        if ($request->get('image')) {
            $image = $request->get('image');
            $name = time() . '.' . explode('/', explode(':', substr($image, 0, strpos($image, ';')))[1])[1];
            \Image::make($request->get('image'))->save(public_path('images/') . $name);
        };
        // ADDS NAME INTO DB
        $image = new FileUpload();
        $image->image_name = $name;
        $image->save();
        return response()->json(['success' => '/images/' . $name], 200);
    }

    public function read()
    //Get the list of the filenames from the DB
    {
        $images = FileUpload::all('image_name')->toArray();
        return response()->json(['success' => $images], 200);
    }

    public function check(Request $request)
    {
        //SOURCE https://www.php.net/manual/es/imagick.getimagecolors.php

        $imageLink = $request->get('imageLink');
        $imageLink = "." . $imageLink;

        $palette = Palette::fromFilename($imageLink);
        // $palette is an iterator on colors sorted by pixel count
        // an extractor is built from a palette
        $extractor = new ColorExtractor($palette);
        // it defines an extract method which return the most “representative” colors (one for this case)
        $colors = $extractor->extract(1);
        $colors = dechex($colors[0]);                       //Converts DEC to HEX
        $colors = str_pad($colors, 6, "0", STR_PAD_LEFT);   //If HEX nr is less then 6 chrs, fills of 0s on the left-side
        $mostUsedRgb[0] = hexdec(substr($colors, 0, 2));    //
        $mostUsedRgb[1] = hexdec(substr($colors, 2, 2));    //Takes the couples of HEX to create RGB
        $mostUsedRgb[2] = hexdec(substr($colors, 4, 2));    //


        $mostUsedLab = $this->rgbTolab($mostUsedRgb);       //Converts to LAB
        $TableRGBs = [                                      // Reference color table
            [0, 255, 255, "Aqua"],
            [0, 0, 0, "Black"],
            [0, 0, 255, "Blue"],
            [255, 0, 255, "Fuchsia"],
            [128, 128, 128, "Gray"],
            [0, 128, 0, "Green"],
            [0, 255, 0, "Lime"],
            [128, 0, 0, "Maroon"],
            [0, 0, 128, "Navy"],
            [128, 128, 0, "Olive"],
            [128, 0, 128, "Purple"],
            [255, 0, 0, "Red"],
            [192, 192, 192, "Silver"],
            [0, 128, 128, "Teal"],
            [255, 255, 255, "White"],
            [255, 255, 0, "Yellow"]
        ];
        $DeltaTab = []; //To check if two colors are similar, we need to use the LAB system and check the "distance" between them
        //https://en.wikipedia.org/wiki/Color_difference#CIELAB_%CE%94E*

        //So it creates a parallel DeltaTab to store deltas with same index of the TableRGB
        for ($num = 0; $num < count($TableRGBs); $num++) {
            $delta = $this->calculateDelta($mostUsedLab, $this->rgbTolab($TableRGBs[$num]));
            $DeltaTab[$num] = $delta;
        };
        $indexPosition = array_search(min($DeltaTab), $DeltaTab);   //Finds the position of the minimun delta, related also to the TableRGB
        return response()->json([
            'position' => json_encode($indexPosition),
            'colorTable' => json_encode($TableRGBs),
            'mostUsed' => json_encode($mostUsedRgb),
            'test' => json_encode($colors)              //Just for checking results
        ], 200);
    }

    private function rgbTolab($inputColor)
    {
        // adapted from https://gist.github.com/manojpandey/f5ece715132c572c80421febebaf66ae

        $num = 0;
        $RGB = [0, 0, 0];
        foreach ($inputColor as $value) {
            $value = floatval($value) / 255;
            if ($value > 0.04045) {
                $value = (($value + 0.055) / 1.055) ** 2.4;
            } else {
                $value = $value / 12.92;
            }
            $RGB[$num] = $value * 100;
            $num = $num + 1;
        };
        $XYZ = [0, 0, 0];
        $X = $RGB[0] * 0.4124 + $RGB[1] * 0.3576 + $RGB[2] * 0.1805;
        $Y = $RGB[0] * 0.2126 + $RGB[1] * 0.7152 + $RGB[2] * 0.0722;
        $Z = $RGB[0] * 0.0193 + $RGB[1] * 0.1192 + $RGB[2] * 0.9505;
        $XYZ[0] = round($X, 4);
        $XYZ[1] = round($Y, 4);
        $XYZ[2] = round($Z, 4);
        // Observer= 2°, Illuminant= D65
        $XYZ[0] = floatval($XYZ[0]) / 95.047;         // ref_X =  95.047
        $XYZ[1] = floatval($XYZ[1]) / 100.0;          // ref_Y = 100.000
        $XYZ[2] = floatval($XYZ[2]) / 108.883;        // ref_Z = 108.883
        $num = 0;
        foreach ($XYZ as $value) {
            if ($value > 0.008856) {
                $value = $value ** (0.3333333333333333);
            } else {
                $value = (7.787 * $value) + (16 / 116);
            }
            $XYZ[$num] = $value;
            $num = $num + 1;
        };
        $Lab = [0, 0, 0];
        $L = (116 * $XYZ[1]) - 16;
        $a = 500 * ($XYZ[0] - $XYZ[1]);
        $b = 200 * ($XYZ[1] - $XYZ[2]);
        $Lab[0] = round($L, 4);
        $Lab[1] = round($a, 4);
        $Lab[2] = round($b, 4);
        return $Lab;
    }

    private function calculateDelta($lab1, $lab2)   //CIE76 Formula
    {
        $lDiff = ($lab2[0] - $lab1[0]) ** 2;
        $aDiff = ($lab2[1] - $lab1[1]) ** 2;
        $bDiff = ($lab2[2] - $lab1[2]) ** 2;
        $delta = sqrt($lDiff + $aDiff + $bDiff);
        return $delta;
    }
}
