<?php

// Verify if user is logged in
$userService = new UserService();
$this->view->vars->loggedInUser = $userService->loginCheck();
Functions::redirectIf(!$this->view->vars->loggedInUser, HOST . '/login/');

// Establish page variables, objects, arrays, etc
$this->view->options->disableView = true;
$userMapper = new UserMapper();

// Check if username is in use
if (!empty($_POST['username']) && strlen($_POST['username']) >= 4) {
    if ($userMapper->getUserByUsername($_POST['username'])) {
        echo json_encode (array ('result' => false, 'message' => (string) Language::getText('error_username_unavailable')));
    } else {
        echo json_encode (array ('result' => true, 'message' => (string) Language::getText('username_available')));
    }
} else {
    echo json_encode (array ('result' => false, 'message' => (string) Language::getText('username_minimum')));
}