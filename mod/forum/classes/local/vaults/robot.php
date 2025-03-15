<?php

/**
 * Extract context vault preprocessor.
 *
 * @package    mod_forum
 * @copyright  2025 Price Chen <drchenforwork@163.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_forum\local\vaults;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once('../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');
use mod_forum\local\container as container;
use mod_forum\local\entities\forum as forum_entity;
use mod_forum\local\entities\discussion as discussion_entity;
use mod_forum\local\entities\post as post_entity;
use mod_forum\local\factories\manager as manager_factory;
use mod_forum\local\managers\capability as capability_manager;
use stdClass;

// 建立一个robot类
class robot {

    /** @var post_entity */
    private $parententity;

    /** @var discussion_entity */
    private $discussionentity;

    /** @var forum_entity */
    private $forumentity;

    /** @var manager_factory */
    private $managerfactory;

    /** @var capability_manager */
    private $capabilitymanager;

    /** @var string */
    private $url;

    /** @var string */
    private $apiKey;

    /** @var int */
    private $maxlen;

    public function __construct(post_entity $parententity, discussion_entity $discussionentity, forum_entity $forumentity){//可以传输数据但是无法赋值到类中
        $this->parententity = $parententity;
        $this->discussionentity = $discussionentity;
        $this->forumentity = $forumentity;
        $this->managerfactory = container::get_manager_factory();
        $this->capabilitymanager = $this->managerfactory->get_capability_manager($forumentity);
        // 后期考虑如何安全的由用户配置输入apikey，目前版本可以考虑在系统环境中插入apikey
        $this->apiKey = getenv('apikey');
    }

    /**
     * 该函数用于获取用户配置模型，确定模型选项，设置API地址以及上传数据长度
     * @return string
     */
    private function select_model(): string{
        global $CFG;

        $model = intval($CFG->forum_setmodel);
        $modelname = null;
        if ($model == 0) {
            $this->url = 'https://api.openai.com/v1/chat/completions';
            $this->maxlen = 2000;
            $modelname = 'gpt-3.5-turbo';
        } else if ($model == 1) {
            $this->url = 'https://api.openai.com/v1/chat/completions';
            $this->maxlen = 4000;
            $modelname = 'gpt-4o-mini';
        }
        // error_log("model---".$model."modelname---".$modelname);

        return $modelname;
    }

    /**
     * 该函数用于调用API接口访问机器人，输入帖子、讨论话题、讨论区标准类，输出机器人回复内容
     * @param stdClass $parent
     * @param stdClass $discussion
     * @param stdClass $forum
     * @return string robot's response
     */
    public function call_robot(stdClass $parent, stdClass $discussion, stdClass $forum): string{
        global $USER;
        global $SESSION;

        // 检验用户是否登录
        $course = $this->forumentity->get_course_record();
        $coursecontext = \context_course::instance($course->id);
        if (!$this->capabilitymanager->can_use_robot($USER, $this->discussionentity, $this->parententity)) {
            if (!isguestuser()) {
                if (!is_enrolled($coursecontext)) {  // 如果用户是游客
                    $SESSION->wantsurl = qualified_me();
                    $SESSION->enrolcancel = get_local_referer(false);
                    redirect(new moodle_url('/enrol/index.php', array('id' => $course->id,
                        'returnurl' => '/mod/forum/view.php?f=' . $forum->id)),
                        get_string('youneedtoenrol'));
                }
    
                // The forum has been locked. Just redirect back to the discussion page.
                if (forum_discussion_is_locked($forum, $discussion)) {
                    redirect(new moodle_url('/mod/forum/discuss.php', array('d' => $discussion->id)));
                }
            }
            throw new \moodle_exception('nopostforum', 'forum');
        }
        
        /** 如果用户已经登录开始调用模型API */

        // 获取讨论下的所有帖子
        $all_posts = forum_get_all_discussion_posts($discussion->id, 'p.created ASC');

        // 初始化合并后的字符串
        $merged_posts = "";

        // 定义递归函数来查找父帖子直到根帖
        function get_parent_posts(stdClass $post, array $all_posts) :string {
            // 如果有父帖子，则查找父帖
            if ($post->parent != 0) {
                // 查找父帖
                $parent_post = null;
                foreach ($all_posts as $p) {
                    if ($p->id == $post->parent) {
                        $parent_post = $p;
                        break;
                    }
                }
                
                if ($parent_post) {
                    // 递归调用，合并父帖信息
                    return get_parent_posts($parent_post, $all_posts) . "Subject: " . $post->subject . "\nMessage: " . $post->message . "\n";
                }
            }
            // 如果是根帖或没有父帖，则直接返回当前帖信息
            return "Subject: " . $post->subject . "\nMessage: " . $post->message . "\n";
        }

        $merged_posts = get_parent_posts($parent, $all_posts);
        $merged_posts = strip_tags($merged_posts); // 去除HTML标签
        $merged_posts = urldecode($merged_posts); // 解码URL编码
        // error_log("merged_posts before_____" . $merged_posts);

        // 检查输入内容是否超过一定长度如果有则递归总结文段并合并
        $short_posts = function(string $posts) use (&$short_posts) {
            $maxlen_hat = $this->maxlen;
            if(strlen($posts) > $maxlen_hat){
                // 通过正则表达式找到最大长度内的最后一个句号、感叹号或问号
                $pattern = '/[.!?。！？][\s]/'; // 匹配句号、感叹号、问号后的空格
                $pos = strpos($posts, $pattern, $maxlen_hat); // 获取最大长度后的第一个空格位置
                if ($pos === false) {
                    // 如果找不到空格，说明没有更多可切割的内容
                    $slice_posts = substr($posts, 0, $maxlen_hat);
                } else {
                    // 从最大长度位置向后查找一个完整的句子或段落
                    $slice_posts = substr($posts, 0, $pos);
                }
                $slice_posts = "you need to summarize this words:" . $slice_posts;
                $slice_posts = self::post_to_api($slice_posts, TRUE); // 总结缩短文段长度
                if (!$slice_posts) {
                    error_log("fail to slice the posts!!!");
                    throw \moodle_exception('slicepostsfail', 'forum');
                }
                if ($pos === false) {
                    $posts = $slice_posts . $short_posts(substr($posts, $maxlen_hat)); // 递归并合并
                } else {
                    $posts = $slice_posts . $short_posts(substr($posts, $pos)); // 递归并合并
                }
                return urldecode($posts);
            } else {
                return urldecode($posts);
            }
        };
        $merged_posts = $short_posts($merged_posts);

        // error_log("merged_posts after_____" . $merged_posts);

        // 调用API进行回答
        $merged_posts .= "you need to reply students and back with Subject:...Message:...";
        $response = self::post_to_api($merged_posts);

        return $response;
    }
    
    /**
     * 构建用以直接访问API的函数
     * @param string $merged_posts
     * @param bool $conclusion
     * @return string
     */
    private function post_to_api(string $merged_posts, bool $conclusion = FALSE) : string {
        global $CFG;

        // 构建机器人人格
        $content = 'You are a helpful IT teacher of high school. You need to read the discussion of students and guide students to think and ask question. You need to pay attention to the interaction with your students. You need to reply by Chinese.';
        if ($conclusion) {
            $content = 'You are a helpful tutor, and you need to summarize the discussion of students.';
        }

        // 检查输入内容是否为空
        if (empty($merged_posts)) {
            echo "Merged posts are empty, cannot send request to the API.\n";
            throw new \moodle_exception('emptymergedposts', 'forum');
        } 

        // 确定模型
        $modelname = self::select_model();
        // 返回的最大token数
        $numlongpost = intval($CFG->forum_longpost);

        // 构建上传内容
        $data = array(
            'model' => $modelname,  // 使用GPT-3.5或GPT-4o模型
            'messages' => array(
                array(
                    'role' => 'system', 'content' => $content
                ),
                array(
                    'role' => 'user', 'content' => $merged_posts
                )
            ),
            'max_tokens' => $numlongpost,  // 控制返回的最大token数
            'temperature' => 0.7  // 控制生成文本的创意度
        );

        // 将数组转为JSON格式
        $data_string = json_encode($data);

        // 初始化cURL会话
        $ch = curl_init($this->url);

        // 设置cURL选项
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',  // 设置为JSON
            'Authorization: Bearer ' . $this->apiKey, // 使用Bearer认证
            'Content-Length: ' . strlen($data_string)
        ));

        // 执行POST访问
        $response = curl_exec($ch);
        // 返回代码获取和处理
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseData = json_decode($response, true);

        // 检查返回代码
        if ($httpCode !== 200) {
            error_log("Cheak the posts data: " . $data_string);
            error_log("API request failed with error code: " . $httpCode . " and response: " . $response);
            // 检查响应数据是否包含错误信息
            if (isset($responseData['error'])) {
                $errorMessage = $responseData['error']['message'] ?? 'Unknown error';
                error_log("API Error: " . $errorMessage . "\n");
                // 你可以在这里根据错误码做一些处理，比如重试或给用户友好提示
                throw new \moodle_exception('failtopostapi', 'forum');
            }
            throw new \moodle_exception('apiunkownerror', 'forum');
        } else if (empty($responseData['choices']) || !isset($responseData['choices'][0]['message']['content'])) {
            // 检查返回内容是否为空
            error_log("API returned an empty or invalid response: " . json_encode($responseData) . "\n");
            throw new \moodle_exception('apibackisnull', 'forum');
        }

        // 将数据类型转换为字符串型
        $response = strval($responseData['choices'][0]['message']['content']);

        return urldecode($response);
    }
}
