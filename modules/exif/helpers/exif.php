<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * This is the API for handling exif data.
 */
class exif_Core {

  protected static $exif_keys;

  static function extract($item) {
    $keys = array();
    // Only try to extract EXIF from photos
    if ($item->is_photo() && $item->mime_type == "image/jpeg") {
      $data = array();
      require_once(MODPATH . "exif/lib/exif.php");
      $exif_raw = read_exif_data_raw($item->file_path(), false);
      if (isset($exif_raw['ValidEXIFData'])) {
        foreach(self::_keys() as $field => $exifvar) {
          if (isset($exif_raw[$exifvar[0]][$exifvar[1]])) {
            $value = $exif_raw[$exifvar[0]][$exifvar[1]];
            if (function_exists("mb_detect_encoding") && mb_detect_encoding($value) != "UTF-8") {
              $value = utf8_encode($value);
            }
            $keys[$field] = utf8::clean($value);

            if ($field == "DateTime") {
              $time = strtotime($value);
              if ($time > 0) {
                $item->captured = $time;
              }
            } else if ($field == "Caption" && !$item->description) {
              $item->description = $value;
            }
          }
        }
      }

      $size = getimagesize($item->file_path(), $info);
      if (is_array($info) && !empty($info["APP13"])) {
        $iptc = iptcparse($info["APP13"]);
        foreach (array("Keywords" => "2#025", "Caption" => "2#120") as $keyword => $iptc_key) {
          if (!empty($iptc[$iptc_key])) {
            $value = implode(" ", $iptc[$iptc_key]);
            if (function_exists("mb_detect_encoding") && mb_detect_encoding($value) != "UTF-8") {
              $value = utf8_encode($value);
            }
            $keys[$keyword] = utf8::clean($value);

            if ($keyword == "Caption" && !$item->description) {
              $item->description = $value;
            }
          }
        }
      }
    }
    $item->save();

    $record = ORM::factory("exif_record")->where("item_id", $item->id)->find();
    if (!$record->loaded) {
      $record->item_id = $item->id;
    }
    $record->data = serialize($keys);
    $record->key_count = count($keys);
    $record->dirty = 0;
    $record->save();
  }

  static function get($item) {
    $exif = array();
    $record = ORM::factory("exif_record")
      ->where("item_id", $item->id)
      ->find();
    if (!$record->loaded) {
      return array();
    }

    $definitions = self::_keys();
    $keys = unserialize($record->data);
    foreach ($keys as $key => $value) {
      $exif[] = array("caption" => $definitions[$key][2], "value" => $value);
    }

    return $exif;
  }

  private static function _keys() {
    if (!isset(self::$exif_keys)) {
      self::$exif_keys = array(
        "Make"            => array("IFD0",   "Make",              t("Camera Maker"),     true),
        "Model"           => array("IFD0",   "Model",             t("Camera Model"),     true),
        "Aperture"        => array("SubIFD", "FNumber",           t("Aperture"),         true),
        "ColorSpace"      => array("SubIFD", "ColorSpace",        t("Color Space"),      true),
        "ExposureBias"    => array("SubIFD", "ExposureBiasValue", t("Exposure Value"),   true),
        "ExposureProgram" => array("SubIFD", "ExposureProgram",   t("Exposure Program"), true),
        "Flash"           => array("SubIFD", "Flash",             t("Flash"),            true),
        "FocalLength"     => array("SubIFD", "FocalLength",       t("Focal Length"),     true),
        "ISO"             => array("SubIFD", "ISOSpeedRatings",   t("ISO"),              true),
        "MeteringMode"    => array("SubIFD", "MeteringMode",      t("Metering Mode"),    true),
        "ShutterSpeed"    => array("SubIFD", "ShutterSpeedValue", t("Shutter Speed"),    true),
        "DateTime"        => array("SubIFD", "DateTimeOriginal",  t("Date/Time"),        true),
        "Copyright"       => array("IFD0",   "Copyright",         t("Copyright"),        false),
        "ImageType"       => array("IFD0",   "ImageType",         t("Image Type"),       false),
        "Orientation"     => array("IFD0",   "Orientation",       t("Orientation"),      false),
        "ResolutionUnit"  => array("IFD0",   "ResolutionUnit",    t("Resolution Unit"),  false),
        "xResolution"     => array("IFD0",   "xResolution",       t("X Resolution"),     false),
        "yResolution"     => array("IFD0",   "yResolution",       t("Y Resolution"),     false),
        "Compression"     => array("IFD1",   "Compression",       t("Compression"),      false),
        "BrightnessValue" => array("SubIFD", "BrightnessValue",   t("Brightness Value"), false),
        "Contrast"        => array("SubIFD", "Contrast",          t("Contrast"),         false),
        "ExposureMode"    => array("SubIFD", "ExposureMode",      t("Exposure Mode"),    false),
        "FlashEnergy"     => array("SubIFD", "FlashEnergy",       t("Flash Energy"),     false),
        "Saturation"      => array("SubIFD", "Saturation",        t("Saturation"),       false),
        "SceneType"       => array("SubIFD", "SceneType",         t("Scene Type"),       false),
        "Sharpness"       => array("SubIFD", "Sharpness",         t("Sharpness"),        false),
        "SubjectDistance" => array("SubIFD", "SubjectDistance",   t("Subject Distance"), false),
        "Caption"         => array("IPTC",   "Caption",           t("Caption"),          false),
        "Keywords"        => array("IPTC",   "Keywords",          t("Keywords"),         false)
      );
    }
    return self::$exif_keys;
  }

  static function stats() {
    $missing_exif = Database::instance()
      ->select("items.id")
      ->from("items")
      ->join("exif_records", "items.id", "exif_records.item_id", "left")
      ->where("type", "photo")
      ->open_paren()
      ->where("exif_records.item_id", null)
      ->orwhere("exif_records.dirty", 1)
      ->close_paren()
      ->get()
      ->count();

    $total_items = ORM::factory("item")->where("type", "photo")->count_all();
    if (!$total_items) {
      return array(0, 0, 0);
    }
    return array($missing_exif, $total_items,
                 round(100 * (($total_items - $missing_exif) / $total_items)));
  }

  static function check_index() {
    list ($remaining) = exif::stats();
    if ($remaining) {
      site_status::warning(
        t('Your EXIF index needs to be updated.  <a href="%url" class="gDialogLink">Fix this now</a>',
          array("url" => url::site("admin/maintenance/start/exif_task::update_index?csrf=__CSRF__"))),
        "exif_index_out_of_date");
    }
  }
}
