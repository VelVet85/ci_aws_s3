<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * Amazon Web Services S3 Wrapper for CodeIgniter
 *
 * @license		MIT License -
 * @author		Wim Reyns
 * @docu		http://todo :)
 * @email		wim@reyns.photo
 *
 * @file		Aws_s3.php
 * @version		1.0.1-alpha
 * @date		14/05/2017
 *
 * Copyright (c) 2017 Wim Reyns
 *
 * Public Functions:
 * ------------------------------------------------------------------------------------------------------------------- *
 * list_files($filter = "", $startAfterKey = "", $limit = 1000)                                                        *
 * file_exist($file)                                                                                                   *
 * filesize($file)                                                                                                     *
 * delete($file)                                                                                                       *
 * play_video($file)                                                                                                   *
 * stream_video($file)                                                                                                 *
 * generate_stream_link($file)                                                                                         *
 * data($file)                                                                                                         *
 * display_image($file)                                                                                                *
 * upload($file, $dir = false)                                                                                         *
 * ------------------------------------------------------------------------------------------------------------------- *
 */

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

class Aws_s3
{
    private $ci;
    private $s3;
    private $res;
    private $bucket;
    private $stream_url;

    public function __construct()
    {
        $this->ci =& get_instance();
        $this->ci->config->load("amazon_s3");
        $this->bucket = $this->ci->config->item('amazon_s3')['amazon_s3_bucket'];
        $this->stream_url = $this->ci->config->item('amazon_s3')['generate_stream_url'];
    }

    /**
     *
     */
    private function initiate()
    {
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region'  => $this->ci->config->item('amazon_s3')['amazon_s3_region'],
            'credentials' => [
                'key'    => $this->ci->config->item('amazon_s3')['amazon_s3_key'],
                'secret' => $this->ci->config->item('amazon_s3')['amazon_s3_secret']
            ],
        ]);
    }


    /**
     * @param string $filter
     * @param string $startAfterKey
     * @param int $limit
     * @return object
     */
    public function list_files($filter = "", $startAfterKey = "", $limit = 1000)
    {
        try {
            $this->initiate();
            $this->res = $this->s3->listObjectsV2([
                        'Bucket' => $this->bucket, // REQUIRED
                        'EncodingType' => 'url',
                        'FetchOwner' => false,
                        'MaxKeys' => $limit,
                        'Prefix' => $filter,
                        'StartAfter' => $startAfterKey,
                    ]);

        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
            $this->res = false;
        }
        return $this->res;
    }

    /**
     * @param $file
     * @return bool
     */
    public function file_exist($file)
    {
        try {
            $this->initiate();
            // test if the object exists, return true false;
            $this->res = $this->s3->doesObjectExist($this->bucket, $file);
        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
            $this->res = false;
        }
        return $this->res;
    }

    /**
     * @param $file
     * @return bool|int
     */
    public function filesize($file)
    {
        try {
            $this->initiate();
            $this->s3->registerStreamWrapper();
            $this->res = filesize("s3://$this->bucket/$file");
        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
            $this->res = false;
        }
        return $this->res;
    }

    /**
     * @param $file
     * @return bool
     */
    public function delete($file)
    {
        try {
            $this->initiate();
            $this->s3->registerStreamWrapper();
            $this->res = unlink("s3://$this->bucket/$file");
        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
            $this->res = false;
        }
        return $this->res;
    }

    /**
     * @param $file
     */
    public function play_video($file)
    {
        $this->initiate();
        $ci =& get_instance();
        $ci->load->library('videostream');
        try {
            $this->s3->registerStreamWrapper();

            $stream = new VideoStream("s3://$this->bucket/$file");
            $stream->start();


        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }

    /**
     * @param $file
     */
    public function stream_video($file)
    {
        try{
            $this->initiate();
            $this->s3->registerStreamWrapper();
            $context = stream_context_create([
                's3' => ['seekable' => true]
            ]);
            if ($stream = fopen("s3://$this->bucket/$file", 'r', false, $context)) {
                // While the stream is still open
                while (!feof($stream)) {
                    // Read 1024 bytes from the stream
                    echo fread($stream, 1024);
                }
                // Be sure to close the stream resource when you're done with it
                fclose($stream);
            }
        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }

    /**
     * @param $file
     * @return string
     */
    public function generate_stream_link($file)
    {
        return "<source src='".$this->stream_url.$file."' type='video/mp4'>";
    }

    /**
     * @param $file
     * @return bool|object
     */
    public function data($file)
    {
        try {
            $this->initiate();
            // Get the object
            $result = $this->s3->getObject(array(
                'Bucket' => $this->bucket,
                'Key'    => $file
            ));
        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
            $result = false;
        }
        if($result){
            return $result['Body'];
        }else{
            return false;
        }
    }

    /**
     * @param $file
     */
    public function display_image($file)
    {
        try {
            $this->initiate();
            // Get the object
            $result = $this->s3->getObject(array(
                'Bucket' => $this->bucket,
                'Key'    => $file
            ));

            // Display the object in the browser
            header("Content-Type: {$result['ContentType']}");
            echo $result['Body'];
        } catch (S3Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }

    /**
     * @param $file
     * @param $dir
     */
    public function upload($file, $dir = false)
    {
        $this->initiate();
        $source = fopen("./upload/$file", "rb");
        $uploader = new MultipartUploader($this->s3, $source, [
            'Bucket' => $this->bucket,
            'Key'    => ($dir ? $dir."/":$dir).$file
        ]);
        do {
            try {
                $result = $uploader->upload();
            } catch (MultipartUploadException $e) {
                rewind($source);
                $uploader = new MultipartUploader($this->s3, $source, [
                    'state' => $e->getState(),
                ]);
            }
        } while (!isset($result));
    }

}