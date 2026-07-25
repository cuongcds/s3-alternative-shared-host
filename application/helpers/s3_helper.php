<?php
defined('BASEPATH') OR exit('No direct script access allowed');

function s3_xml_header()
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
}

function s3_list_all_my_buckets_xml(array $buckets, $ownerId)
{
    $xml = s3_xml_header();
    $xml .= '<ListAllMyBucketsResult xmlns="http://s3.open-s3.local/doc/2026-01-01/">';
    $xml .= '<Owner><ID>' . htmlspecialchars($ownerId, ENT_XML1) . '</ID>'
        . '<DisplayName>' . htmlspecialchars($ownerId, ENT_XML1) . '</DisplayName></Owner>';
    $xml .= '<Buckets>';
    foreach ($buckets as $b) {
        $xml .= '<Bucket>'
            . '<Name>' . htmlspecialchars($b['name'], ENT_XML1) . '</Name>'
            . '<CreationDate>' . gmdate('Y-m-d\TH:i:s.000\Z', strtotime($b['created_at'])) . '</CreationDate>'
            . '</Bucket>';
    }
    $xml .= '</Buckets></ListAllMyBucketsResult>';
    return $xml;
}

/**
 * Human-readable byte size, used by the admin panel (docs/plans_v2.md) for
 * bucket/object listings — not part of the S3 XML responses.
 */
function s3_format_bytes($bytes)
{
    $bytes = (float) $bytes;
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (int) $bytes : round($bytes, 1)) . ' ' . $units[$i];
}

function s3_list_bucket_result_xml($bucketName, array $objects, $prefix, $maxKeys, $isTruncated)
{
    $xml = s3_xml_header();
    $xml .= '<ListBucketResult xmlns="http://s3.open-s3.local/doc/2026-01-01/">';
    $xml .= '<Name>' . htmlspecialchars($bucketName, ENT_XML1) . '</Name>';
    $xml .= '<Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix>';
    $xml .= '<MaxKeys>' . (int) $maxKeys . '</MaxKeys>';
    $xml .= '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';
    foreach ($objects as $o) {
        $xml .= '<Contents>'
            . '<Key>' . htmlspecialchars($o['object_key'], ENT_XML1) . '</Key>'
            . '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', strtotime($o['created_at'])) . '</LastModified>'
            . '<ETag>&quot;' . htmlspecialchars($o['etag'], ENT_XML1) . '&quot;</ETag>'
            . '<Size>' . (int) $o['size'] . '</Size>'
            . '<StorageClass>STANDARD</StorageClass>'
            . '</Contents>';
    }
    $xml .= '</ListBucketResult>';
    return $xml;
}
