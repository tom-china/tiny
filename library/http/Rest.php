<?php
/**
 * Tiny Api Framework.
 *
 * @link https://github.com/cizel/tiny
 *
 * @copyright 2017-2017 i@cizel.cn
 */

namespace Http;

use Config;
use Str;
use Yaf_Dispatcher;
use Exception\InvalidValueException;
use Yaf_Controller_Abstract as BaseController;

class Rest extends BaseController
{
    protected $response = '0';

    protected $code = 200;

    private $config;

    public function __destruct()
    {
        header('Content-Type: application/json; charset=utf-8', true, $this->code);
        echo json_encode($this->response, $this->config['json']);
    }

    /**
     *  Initialize the Controller
     */
    public function init()
    {
        /** Disable view template **/
        Yaf_Dispatcher::getInstance()->disableView();

        /** Set the cross-domain request header **/
        $corsConfig = Config::get('cors');

        if (is_null($corsConfig)) {
            throw new InvalidValueException('cors config not found');
        }

        $this->corsHeader($corsConfig->toArray());

        $request = $this->getRequest();

        $method = $request->getMethod();

        $type = $request->getServer('CONTENT_TYPE');

        if ($method === 'OPTIONS') {
            /*cors 跨域header应答,只需响应头即可*/
            exit;
        } elseif (Str::contains($type, 'application/json')) {
            /*json 数据格式*/
            if ($inputs = file_get_contents('php://input')) {
                $input_data = json_decode($inputs, true);
                if ($input_data) {
                    $GLOBALS['_'.$method] = $input_data;
                } else {
                    parse_str($inputs, $GLOBALS['_'.$method]);
                }
            }
        } elseif ($method === 'PUT' && ($inputs = file_get_contents('php://input'))) {
            /*直接解析*/
            parse_str($inputs, $GLOBALS['_PUT']);
        }


        /*Action路由*/
        $action        = $request->getActionName();
        $this->config = Config::get('rest')->toArray();

        if (is_numeric($action)) {
            /*数字id绑定参数*/
            $request->setParam($this->config['param'], intval($action));
            //提取请求路径
            $path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] :
                strstr($_SERVER['REQUEST_URI'].'?', '?', true);
            $path   = substr(strstr($path, $action), strlen($action) + 1);
            $action = $path ? strstr($path.'/', '/', true) : $this->config['action'];
        }

        $restAction = $method.$action; //对应REST_Action

        if (method_exists($this, $restAction.'Action')) {
            /*存在对应的操作*/
            $request->setActionName($restAction);
        } elseif (!method_exists($this, $action.'Action')) {
            /*action和REST_action 都不存在*/
            if (method_exists($this, $this->config['none'].'Action')) {
                $request->setActionName($this->config['none']);
            } else {
                $this->response(-8, array(
                    'error'      => '未定义操作',
                    'method'     => $method,
                    'action'     => $action,
                    'controller' => $request->getControllerName(),
                    'module'     => $request->getmoduleName(),
                ), 404);
                exit;
            }
        } elseif ($action !== $request->getActionName()) {
            /*修改后的$action存在而$rest_action不存在,绑定参数默认控制器*/
            $request->setActionName($action);
        }
    }

    protected function response($status, $data = null, $code = null)
    {
        $this->response = array(
            $this->config['status'] => $status,
            $this->config['data']   => $data,
        );
        ($code > 0) && $this->code = $code;
    }

    protected function success($data = null, $code = 200)
    {
        $this->response = array(
            $this->config['status'] => 1,
            $this->config['data']   => $data,
        );
        $this->code = $code;
    }

    protected function fail($data = null, $code = 200)
    {
        $this->response = array(
            $this->config['status'] => 0,
            $this->config['data']   => $data,
        );
        $this->code = $code;
    }


    private function corsHeader(array $cors)
    {
        //请求来源站点
        $from = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] :
            (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null);

        if ($from) {
            $domains = $cors['Access-Control-Allow-Origin'];
            if ($domains !== '*') {//非通配
                $domain = strtok($domains, ',');
                while ($domain) {
                    if (strpos($from, rtrim($domain, '/')) === 0) {
                        $cors['Access-Control-Allow-Origin'] = $domain;
                        break;
                    }
                    $domain = strtok(',');
                }
                if (!$domain) {
                    /*非请指定的求来源,自动终止响应*/
                    header('Forbid-Origin: '.$from);
                    return;
                }
            } elseif ($cors['Access-Control-Allow-Credentials'] === 'true') {
                /*支持多域名和cookie认证,此时修改源*/
                $cors['Access-Control-Allow-Origin'] = $from;
            }
            foreach ($cors as $key => $value) {
                header($key.': '.$value);
            }
        }
    }
}
