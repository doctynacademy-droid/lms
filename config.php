<?php
// Doctyn Academy FCCM LMS — settings. Edit this file in File Manager (right-click > Edit).
return array(

    // The administrator login created the first time the site is opened.
    'admin_id'       => 'admin',
    // REQUIRED: choose a long password of your own (at least 8 characters). Used only on first run;
    // after that you can change it inside the LMS under My Account.
    'admin_password' => 'CHANGE-ME-TO-A-LONG-PASSWORD',

    // Where the database and uploaded files are kept. Leave this blank: the LMS then uses a folder called
    // "doctyn-fccm-data" next to your public_html folder (outside the website, so nobody can download it).
    // Only fill this in if you want somewhere else, e.g. '/home/YOURUSERNAME/my-lms-data'.
    'data_dir'       => '',

    // Largest single upload in MB. Your hosting may enforce a lower limit; for big videos use a YouTube link.
    'max_upload_mb'  => 512,

    // Set to true only if the site is behind a proxy/CDN that forwards the visitor's real IP.
    'trust_proxy'    => false,
);
