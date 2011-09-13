<!DOCTYPE html>
<html>
<head>
<title><?=$meta->title?></title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<?php View::WriteMeta(); ?>
<link rel="stylesheet" type="text/css" href="<?=THEME?>/css/reset.css" />
<link rel="stylesheet" type="text/css" href="<?=THEME?>/css/main.css" />
<link rel="stylesheet" type="text/css" href="<?=THEME?>/css/pages.css" />
<?php View::WriteCSS(); ?>

</head>
<body class="<?=Language::GetCSSName()?>">

<!-- BEGIN WRAPPER -->
<div id="wrapper">

    <?php View::Block ('header_nav.tpl'); ?>

    <!-- BEGIN MAIN -->
    <div id="main">

        <!-- BEGIN CONTENT -->
        <div id="content">