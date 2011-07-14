<?php

class Filesystem {

    static public $native;
    static private $ftp_stream;
    static private $ftp_host;
    static private $ftp_username;
    static private $ftp_password;


    /**
     * Notes:
     * If $native is true:
     *      a) Webserver owns and runs codebase
     *      b) Use native for everything
     *      c) FTP is not involved at all
     *
     * If $native is false:
     *      a) FTP user owns codebase
     *      b) Use FTP for everything
     *      c) Use native for any file not owned by FTP and writeable by Webserver

     *          Explanation: The mode is FTP, thus all files should be owned by FTP.
     *          If a file is not owned by FTP, it's assumed to be owned by Webserver
     *          and it [Webserver] should have write access. If not owned by Webserver
     *          then failure is imminent because neither Webserver nor FTP would have
     *          sufficient permissions to perform ALL filesystem operations anyway.
     *
     */
    static function Open() {

        // Check if native PHP methods should be used - Test 1
        self::$native = (is_writable (DOC_ROOT) && getmyuid() == fileowner (DOC_ROOT)) ? true : false;

        // Check if native PHP methods should be used - Test 2
        if (self::$native) {

            // Create temporary file
            $native_check_file = DOC_ROOT . '/native-check' . time();
            $handle = fopen ($native_check_file, 'w');
            fwrite ($handle, 'Native Check');

            // Check if webserver/PHP has filesystem access
            self::$native = (fileowner ($native_check_file) == getmyuid()) ? true : false;

            // Remove temporary file
            fclose ($handle);
            unlink ($native_check_file);
            
        }


        // Login to server via FTP if PHP doesn't have write access
        if (!self::$native) {

            // Set FTP login settings
            self::$ftp_host = Settings::Get ('ftp_host');
            self::$ftp_username = Settings::Get ('ftp_username');
            self::$ftp_password = Settings::Get ('ftp_password');

            // Connect to FTP host
            self::$ftp_stream = ftp_connect (self::$ftp_host);
            if (!self::$ftp_stream) throw new Exception ("Unable to connect to FTP host (" . self::$ftp_host . ")");

            // Login with username and password
            if (!ftp_login (self::$ftp_stream, self::$ftp_username, self::$ftp_password)) {
                throw new Exception ("Unable to login to FTP server (Username: '" . self::$ftp_username . "', Password: '" . self::$ftp_password. "')");
            }

        }

        return (self::$native) ? 'native' : 'ftp';

    }




    static function Close() {
        if (!self::$native) ftp_close (self::$ftp_stream);
    }




    static function Delete ($filename) {

        // If dir. delete contents then dir., if file simply delete
        if (is_dir ($filename)) {

            // Strip trailing slash
            $filename = rtrim ($filename, '/');

            // Delete directory contents recursively
            $contents = array_diff (scandir ($filename), array ('.', '..'));
            foreach ($contents as $file) {
                self::Delete ($filename . '/' . $file);
            }

            // Delete directory
            if (self::CanUseNative ($filename)) {
                if (!rmdir ($filename)) throw new Exception ("Unable to delete directory ($filename)");
            } else {
                if (!ftp_rmdir (self::$ftp_stream, $filename)) throw new Exception ("Unable to delete directory via FTP ($filename)");
            }

        } else {

            // Delete file
            if (self::CanUseNative ($filename)) {
                if (!unlink ($filename)) throw new Exception ("Unable to delete file ($filename)");
            } else {
                if (!ftp_delete (self::$ftp_stream, $filename)) throw new Exception ("Unable to delete file via FTP ($filename)");
            }

        }

        return true;

    }




    static function Create ($filename) {

        // Create folder structure if non-existant
        if (!file_exists (dirname ($filename))) self::CreateDir (dirname ($filename));

        // Perform action directly if able, use FTP otherwise
        if (self::$native) {
            if (file_put_contents ($filename, '') === false) throw new Exception ("Unable to create file ($filename)");
        } else {

            $stream = tmpfile();
            if (!ftp_fput (self::$ftp_stream, $filename, $stream, FTP_BINARY)) {
                throw new Exception ("Unable to create file via FTP ($filename)");
            }
            fclose ($stream);

        }

        self::SetPermissions ($filename, 0644);
        return true;

    }




    static function CreateDir ($dirname) {

        // Create folder structure if non-existant
        if (!file_exists (dirname ($dirname))) self::CreateDir (dirname ($dirname));

        // If dir exists, just update permissions
        if (file_exists ($dirname)) return self::SetPermissions ($dirname, 0755);

        // Perform action directly if able, use FTP otherwise
        if (self::$native) {
            if (!mkdir ($dirname)) throw new Exception ("Unable to create directory ($dirname)");
        } else {
            if (!ftp_mkdir (self::$ftp_stream, $dirname)) throw new Exception ("Unable to create directory via FTP ($dirname)");
        }

        self::SetPermissions ($dirname, 0755);
        return true;

    }




    static function Write ($filename, $content) {

        // Perform action directly if able, use FTP otherwise
        if (self::$native) {

            $current_content = file_get_contents ($filename, $content);
            if (file_put_contents ($filename, $current_content . $content) === false) {
                throw new Exception ("Unable to write content to file ($filename)");
            }

        } else {

            // Load existing content
            $stream = tmpfile();
            if (!ftp_fget (self::$ftp_stream, $stream, $filename, FTP_BINARY)) {
                throw new Exception ("Unable to open file for reading/writing via FTP ($filename)");
            }

            // Append new content
            fwrite ($stream, $content);
            fseek ($stream, 0);

            // Save back to file
            $result = ftp_fput (self::$ftp_stream, $filename, $stream, FTP_BINARY);
            if (!$result) {
                throw new Exception ("Unable to write content to file via FTP ($filename)");
            }
            fclose ($stream);

        }

        return true;

    }




    static function Copy ($filename, $new_filename) {

        // Create folder structure if non-existant
        if (!file_exists (dirname ($new_filename))) self::CreateDir (dirname ($new_filename));

        // Perform action directly if able, use FTP otherwise
        if (self::$native) {
            if (!copy ($filename, $new_filename)) throw new Exception ("Unable to copy file ($filename to $new_filename)");
        } else {

            // Load original content
            $stream = tmpfile();
            if (!ftp_fget (self::$ftp_stream, $stream, $filename, FTP_BINARY)) {
                throw new Exception ("Unable to open file for reading/copying via FTP ($filename)");
            }

            // Overwrite new location
            fseek ($stream, 0);
            if (!ftp_fput (self::$ftp_stream, $new_filename, $stream, FTP_BINARY)) {
                throw new Exception ("Unable to copy file via FTP ($filename to $new_filename)");
            }
            fclose ($stream);
            self::SetPermissions ($new_filename, 0644);

        }

        return true;

    }




    static function CopyDir ($src_dirname, $dst_dirname) {

        // Retrieve directory contents, minus . & ..
        $contents = array_diff (scandir ($src_dirname), array ('.', '..'));

        // Simply create dir if src dir is empty
        if (empty ($contents))  self::CreateDir ($dst_dirname);

        // Check & copy directory contents
        foreach ($contents as $child_item) {

            // Generate new src & dest locations
            $new_src_dirname = $src_dirname . '/' . $child_item;
            $new_dst_dirname = $dst_dirname . '/' . $child_item;

            if (is_dir ($new_src_dirname)) {
                // Copy directory recursively
                self::CopyDir ($new_src_dirname, $new_dst_dirname);
            } else {
                // Copy file
                self::Copy ($new_src_dirname, $new_dst_dirname);
            }

        }

        return true;

    }




    static function SetPermissions ($filename, $permissions) {

        // Perform action directly if able, use FTP otherwise
        if (self::CanUseNative ($filename)) {
            if (!chmod ($filename, $permissions)) {
                throw new Exception ("Unable to set permissions ($permissions on $filename)");
            }
        } else {
            if (ftp_chmod (self::$ftp_stream, $permissions, $filename) === false) {
                throw new Exception ("Unable to set permissions via FTP ($permissions on $filename)");
            }
        }
        return true;

    }




    static function Rename ($old_filename, $new_filename) {

        // Perform action directly if able, use FTP otherwise
        if (self::$native) {
            if (!rename ($old_filename, $new_filename)) {
                throw new Exception ("Unable to rename file ($old_filename to $new_filename)");
            }
        } else {
            if (!ftp_rename (self::$ftp_stream, $old_filename, $new_filename)) {
                throw new Exception ("Unable to rename file via FTP ($old_filename to $new_filename)");
            }
        }
        return true;

    }




    static function Extract ($zipfile, $extract_to = null) {

        // Open zip file
        $zip = new ZipArchive();
        if (!$zip->open ($zipfile)) throw new Exception ("Unable to open zip file ($zipfile)");

        // Extract contents to given location or same dir. if not specified
        $extract_to = ($extract_to) ? $extract_to : dirname ($zipfile);
        if (!$zip->extractTo ($extract_to)) throw new Exception ("Unable to extract zip file ($zipfile to $extract_to)");
        return true;

    }




    static function CanUseNative ($filename) {
        return (self::$native || (is_writable($filename) && fileowner ($filename) != fileowner (DOC_ROOT)));
    }

}

?>