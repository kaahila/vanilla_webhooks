<?php

/**
 * Vanilla_Webhooks Plugin.
 */
class Vanilla_Webhooks extends Gdn_Plugin
{

    const DEFAULT_CONFIG_KEY = "Garden.Vanilla_Webhooks";
    const SETTINGS_URL = "/settings/vanilla_webhooks";

    const DEBUG = false;

    // Global 0 = no default, 1 is default + override, 2 is plugin level setting only
    const OPTIONS = [
        'auth_header_name' => [
            'global' => 2,
            'title' => 'Auth Header Name',
            'description' => 'The Name of the Auth Header',
            'type' => 'textbox'
        ],
        'auth_header_value' => [
            'global' => 2,
            'title' => 'Auth Header Value',
            'description' => 'The Value of the Auth Header',
            'type' => 'textbox'
        ],
        'globally_on' => [
            'global' => 2,
            'title' => 'Globally on',
            'description' => 'Are default settings active for each category?',
            'type' => 'toggle'
        ],
        'locally_on' => [
            'global' => 0,
            'title' => 'Category on',
            'description' => 'Can this category trigger notifications?',
            'type' => 'toggle',
            'default' => '1'
        ],
        'webhook_url' => [
            'global' => 1,
            'title' => 'webhook URL',
            'description' => 'webhook URL',
            'type' => 'textbox'
        ],
        'discussion_webhook' => [
            'global' => 1,
            'title' => 'Discussion',
            'description' => 'trigger on discussion save',
            'type' => 'toggle',
            'default' => '1'
        ],
        'usermodel_webhook' => [
            'global' => 1,
            'title' => 'Usermodel',
            'description' => 'trigger on usermodel save',
            'type' => 'toggle',
            'default' => '1'
        ],
        'child_category_webhook' => [
            'global' => 1,
            'title' => 'Category',
            'description' => 'trigger on category save',
            'type' => 'toggle',
            'default' => '1'
        ],
        'comment_webhook' => [
            'global' => 1,
            'title' => 'Comment',
            'description' => 'trigger on comment save',
            'type' => 'toggle',
            'default' => '1'
        ],
    ];

    /**
     * This will run when you "Enable" the plugin.
     *
     * @return void
     */
    public function setup()
    {
        $this->structure();
    }

    private static function debug($data)
    {
        if (self::DEBUG) {
            $string = var_export($data, true);
            error_log($string);
        }
    }

    /**
     *
     * Runs structure.php on /utility/update and on enabling the plugin.
     *
     * @return void
     */
    public function structure()
    {
        $structure = Gdn::structure()->table('Category');
        foreach (self::OPTIONS as $k => $o) {
            if ($o['global'] == 2) {
                continue;
            }
            $type = ($o['type'] == 'toggle' ? 'tinyint(1)' : 'varchar(255)');
            $default = ($o['type'] == 'toggle' ? '0' : true);
            if (isset($o['default'])) {
                $default = $o['default'];
            }
            $structure->column($k, $type, $default);
        }

        $structure->set();
    }

    public static function getOptionValue($categoryID, $option, $errorValue = false, &$step = 0)
    {
        $step += 1;
        if ($step > 100) {
            // Just to be sure
            return false;
        }
        $categoryID = filter_var($categoryID, FILTER_VALIDATE_INT);
        if (!$categoryID || $categoryID < 1) {
            if (Gdn::config(self::DEFAULT_CONFIG_KEY . ".globally_on") === "1") {
                return Gdn::config(self::DEFAULT_CONFIG_KEY . ".$option");
            } else {
                return $errorValue;
            }
        }

        $category = CategoryModel::instance()->getID($categoryID);
        $slug = val($option, $category);

        if (!$slug) {
            $parentID = val('ParentCategoryID', $category);
            $slug = self::getOptionValue($parentID, $option, $step);
        }
        return $slug;
    }

    /**
     * Add additional inputs to the category page form.
     *
     * @param VanillaSettingsController $sender The controller for the settings page.
     *
     * @return void
     */
    public function vanillaSettingsController_afterCategorySettings_handler($sender)
    {
        $return = '';

        foreach (self::OPTIONS as $k => $o) {
            if ($o['global'] == 2) {
                continue;
            }
            $return .= '<li class="form-group">';
            $return .= '    <div class="label-wrap">';
            $return .= '       ' . $sender->Form->label(ucfirst($o['title']), $k);
            $return .= '   </div>';
            $return .= '   <div class="input-wrap">';
            if ($o['type'] == 'toggle') {
                $return .= $sender->Form->toggle($k);
            } else {
                $return .= $sender->Form->textBox($k, ['MultiLine' => false]);
            }
            $return .= '   </div>';
            $return .= '</li>';
        }

        echo $return;
    }

    /**
     * Create the configuration page for the plugin
     *
     * @param VanillaSettingsController $sender The settings controller
     *
     * @return void
     */
    public function settingsController_vanilla_webhooks_create($sender)
    {
        $sender->permission('Garden.Community.Manage');
        $sender->setHighlightRoute(self::SETTINGS_URL);
        $sender->title(t('Vanilla Webhooks'));
        $configurationModule = new ConfigurationModule($sender);
        $options = [];
        $prekey = self::DEFAULT_CONFIG_KEY;
        foreach (self::OPTIONS as $k => $o) {
            if ($o['global'] == 0) {
                continue;
            }
            $options[$prekey . "." . $k] = [
                'LabelCode' => t(($o['global'] == 1 ? 'Default ' : '') . $o['description']),
                'Control' => $o['type']
            ];
        }
        $configurationModule->initialize($options);
        $sender->setData('ConfigurationModule', $configurationModule);
        $configurationModule->renderAll();
    }

    private static function getCommentUrl($id): string
    {
        $commentModel = new CommentModel();
        $comment = $commentModel->getID($id);
        return commentUrl($comment, true);
    }

    private static function getDiscussionUrl($id): string
    {
        $discussionModel = new DiscussionModel();
        $discussion = $discussionModel->getID($id);
        return discussionUrl($discussion, 1, true);
    }

    private static function getCategoryUrl($id): string
    {
        $categoryModel = new CategoryModel();
        $category = $categoryModel->getID($id);
        return categoryUrl($category, 1, true);
    }

    private static function getUserUrl($id): string
    {
        $userModel = new UserModel();
        $user = $userModel->getID($id);
        return userUrl($user, 1, true);
    }

    private static function callWebhook($hookURL, $hookObject, $requestType = "POST")
    {
        if (!function_exists('curl_version')) {
            self::debug("CURL not installed, exiting");
            return;
        }
        self::debug("Sending CURL request: $hookObject");
        $ch = curl_init();

        $headers = [
            "Length:" . strlen($hookObject),
            "Content-Type:application/json",
            self::getOptionValue(0, "auth_header_name") . ":" .
            self::getOptionValue(0, "auth_header_value"),
        ];
        self::debug("Headers: " . json_encode($headers));

        curl_setopt_array($ch, [
            CURLOPT_URL => $hookURL,
            CURLOPT_CUSTOMREQUEST => $requestType,
            CURLOPT_POSTFIELDS => $hookObject,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        try {
            $response = curl_exec($ch);

            self::debug("Response: $response");
        } catch (\Exception $e) {
            self::debug("Exception: " . $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    private static function getUserLink($id, $markdown = false)
    {
        $user = Gdn::userModel()->getID($id);
        $userURL = htmlspecialchars(url('/profile/' . $user->Name, true));
        if ($markdown) {
            return "[" . $user->Name . "]($userURL)";
        } else {
            return $userURL;
        }
    }

    /**
     * Save Handler for the userModel.
     *
     * @param \UserModel $sender
     * @param array $args
     */
    public function UserModel_afterSave_handler(\UserModel $sender, array $args)
    {
        self::debug("UserModel_afterSave_handler");
        self::debug($args);
        self::debug("----------------------");
        $user = $args['FormPostValues'];
        if (!self::getOptionValue(-1, "usermodel_webhook")) {
            self::debug("Not sending for user_webhook, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue(-1, "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }
        unset($user['Password']);
        unset($user['LastIPAddress']);
        unset($user['InsertIPAddress']);
        unset($user['UpdateIPAddress']);
        $user['Url'] = self::getUserUrl($args['CategoryID']);
        $user['Type'] = 'User';
        $user['UserID'] = $args['UserID'];
        self::callWebhook($hookURL, json_encode($user, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Save Handler for the discussionModel.
     *
     * @param \DiscussionModel $sender
     * @param array $args
     */
    public function UserModel_BeforeDeleteUser_handler(UserModel $sender, array $args)
    {
        self::debug("UserModel_BeforeDeleteUser_handler");
        self::debug($args);
        self::debug("----------------------");
        if (!self::getOptionValue(-1, "usermodel_webhook")) {
            self::debug("Not sending for user_webhook, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue(-1, "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }
        $user['UserID'] = $args['UserID'];
        $user['Type'] = 'User';
        self::callWebhook($hookURL, json_encode($user, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "DELETE");
    }


    public function postController_afterDiscussionSave_handler($sender, $args)
    {
        self::debug("postController_afterDiscussionSave_handler");
        self::debug($args);
        self::debug("----------------------");
        if (!$args['Discussion']) {
            self::debug("No discussion, exiting");
            return;
        }
        $discussion = $args['Discussion'];
        if (!self::validateHook($discussion->CategoryID)) {
            return;
        }
        if (!self::getOptionValue($discussion->CategoryID, "discussion_webhook")) {
            self::debug("Not sending for discussion_webhook, exiting");
            return;
        }
        $discussionID = $discussion->DiscussionID;
        if (!$discussionID) {
            self::debug("No discussion ID, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue($discussion->CategoryID, "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }

        unset($discussion->LastIPAddress);
        unset($discussion->InsertIPAddress);
        unset($discussion->UpdateIPAddress);
        $discussion = self::classToArray($discussion);
        $discussion['Url'] = self::getDiscussionUrl($discussionID);
        $discussion['Type'] = 'Discussion';
        $hookObject = json_encode($discussion, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        self::callWebhook($hookURL, $hookObject);
    }


    public function DiscussionModel_DeleteDiscussion_handler(DiscussionModel $sender, array $args)
    {
        self::debug("DiscussionModel_DeleteDiscussion_handler");
        self::debug($args);
        self::debug("----------------------");
        $categoryID = $args['Discussion']['CategoryID'];
        if (!self::validateHook($categoryID)) {
            return;
        }
        if (!self::getOptionValue($categoryID, "discussion_webhook")) {
            self::debug("Not sending for discussion_webhook, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue($categoryID, "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }
        $discussion['DiscussionID'] = $args['DiscussionID'];
        $discussion['Type'] = 'Discussion';
        self::callWebhook($hookURL, json_encode($discussion, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "DELETE");
    }

    /**
     * Save Handler for categoryModel.
     *
     * @param CategoryModel $sender
     * @param array $args
     */
    public function CategoryModel_AfterSaveCategory_handler(CategoryModel $sender, array $args)
    {

        self::debug("CategoryModel_AfterSaveCategory_handler");
        self::debug($args);
        self::debug("----------------------");
        $category = $args['FormPostValues'];
        $parentCategoryId = $category['ParentCategoryID'];
        if (!self::getOptionValue($parentCategoryId, "child_category_webhook")) {
            self::debug("Not sending for child_category_webhook, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue($parentCategoryId, "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }
        $category['Url'] = self::getCategoryUrl($args['CategoryID']);
        $category['Type'] = 'Category';
        $category['CategoryID'] = $args['CategoryID'];
        self::callWebhook($hookURL, json_encode($category, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function CategoryModel_AfterDeleteCategory_handler(CategoryModel $sender, array $args)
    {
        self::debug("CategoryModel_AfterDeleteCategory_handler");
        self::debug($args);
        self::debug("----------------------");
        $parentCategoryId = $args['Category']['ParentCategoryID'];
        if (!self::getOptionValue($parentCategoryId, "child_category_webhook")) {
            self::debug("Not sending for child_category_webhook, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue($parentCategoryId, "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }
        $category['CategoryID'] = $args['CategoryID'];
        $category['Type'] = 'Category';
        self::callWebhook($hookURL, json_encode($category, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "DELETE");
    }

    protected static function validateHook($categoryID): bool
    {
        self::debug("Validating hook for category $categoryID");
        if (!self::getOptionValue($categoryID, "locally_on")) {
            return false;
        }
        return true;
    }

    public function postController_afterCommentSave_handler($sender, $args)
    {
        self::debug("postController_afterCommentSave_handler");
        self::debug($args);
        self::debug("----------------------");
        if (!$args['Discussion']) {
            self::debug("No discussion, exiting");
            return;
        }
        $discussion = $args['Discussion'];
        if (!self::validateHook($discussion->CategoryID)) {
            return;
        }
        if (!self::getOptionValue($discussion->CategoryID, "comment_webhook")) {
            self::debug("Not sending for comment_webhook, exiting");
            return;
        }
        if (!$args['Comment']) {
            self::debug("No post, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue($discussion->CategoryID, "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }

        unset($args['Comment']->LastIPAddress);
        unset($args['Comment']->InsertIPAddress);
        unset($args['Comment']->UpdateIPAddress);
        $comment = self::classToArray($args['Comment']);
        $commentUrl = self::getCommentUrl($comment['CommentID']);
        $discussionUrl = self::getDiscussionUrl($comment['DiscussionID']);
        $comment['Url'] = $commentUrl;
        $comment['Type'] = 'Comment';
        $comment['DiscussionUrl'] = $discussionUrl;
        $hookObject = json_encode($comment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        self::callWebhook($hookURL, $hookObject);
    }

    public function CommentModel_DeleteComment_handler(CommentModel $sender, $args)
    {
        self::debug("CommentModel_DeleteComment_handler");
        self::debug($args);
        self::debug("----------------------");
        if (!$args['Discussion']) {
            self::debug("No discussion, exiting");
            return;
        }
        $discussion = $args['Discussion'];
        if (!self::validateHook($discussion['CategoryID'])) {
            return;
        }
        if (!self::getOptionValue($discussion['CategoryID'], "comment_webhook")) {
            self::debug("Not sending for comment_webhook, exiting");
            return;
        }
        if (!$hookURL = self::getOptionValue($discussion['CategoryID'], "webhook_url")) {
            self::debug("No Webhook URL for this category, exiting");
            return;
        }
        $comment['CommentID'] = $args['CommentID'];
        $comment['Type'] = 'Comment';
        self::callWebhook($hookURL, json_encode($comment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "DELETE");
    }

    private static function classToArray($class)
    {
        return json_decode(json_encode($class), true);
    }
}