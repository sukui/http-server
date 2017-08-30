<?php

namespace Zan\Framework\Network\Http;

use Zan\Framework\Network\Http\ServerStart\InitializeProxyIps;
use Zan\Framework\Network\Http\ServerStart\InitializeRouter;
use Zan\Framework\Network\Http\ServerStart\InitializeUrlRule;
use Zan\Framework\Network\Http\ServerStart\InitializeMiddleware;
use Zan\Framework\Network\Http\ServerStart\InitializeExceptionHandlerChain;
use Zan\Framework\Network\Server\Monitor\Worker;
use Zan\Framework\Network\Server\ServerStart\InitLogConfig;
use Zan\Framework\Network\Server\WorkerStart\InitializeConnectionPool;
use Zan\Framework\Network\Server\WorkerStart\InitializeErrorHandler;
use Zan\Framework\Network\Server\WorkerStart\InitializeHawkMonitor;
use Zan\Framework\Network\Server\WorkerStart\InitializeServiceChain;
use Zan\Framework\Network\Server\WorkerStart\InitializeWorkerMonitor;
use Zan\Framework\Network\Server\WorkerStart\InitializeServerDiscovery;
use Zan\Framework\Network\Http\ServerStart\InitializeUrlConfig;
use Zan\Framework\Network\Http\ServerStart\InitializeQiniuConfig;
use swoole_http_request as SwooleHttpRequest;
use swoole_http_response as SwooleHttpResponse;
use Zan\Framework\Network\Server\ServerBase;
use Zan\Framework\Network\ServerManager\ServerStore;
use Zan\Framework\Network\ServerManager\ServerDiscoveryInitiator;
use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Network\Http\ServerStart\InitializeSqlMap;

class Server extends ServerBase
{
    protected $serverStartItems = [
        InitializeRouter::class,
        InitializeUrlRule::class,
        InitializeUrlConfig::class,
        InitializeQiniuConfig::class,
        InitializeMiddleware::class,
        InitializeExceptionHandlerChain::class,
        InitLogConfig::class,
        InitializeSqlMap::class,
        InitializeProxyIps::class,
    ];

    protected $workerStartItems = [
        InitializeErrorHandler::class,
        InitializeWorkerMonitor::class,
        InitializeHawkMonitor::class,
        InitializeConnectionPool::class,
        InitializeServerDiscovery::class,
        InitializeServiceChain::class,
    ];

    public function setSwooleEvent()
    {
        $this->swooleServer->on('start', [$this, 'onStart']);
        $this->swooleServer->on('shutdown', [$this, 'onShutdown']);

        $this->swooleServer->on('workerStart', [$this, 'onWorkerStart']);
        $this->swooleServer->on('workerStop', [$this, 'onWorkerStop']);
        $this->swooleServer->on('workerError', [$this, 'onWorkerError']);

        $this->swooleServer->on('request', [$this, 'onRequest']);
    }

    protected function init()
    {
        $config = Config::get('registry');
        if (!isset($config['app_names']) || [] === $config['app_names']) {
            return;
        }
        ServerStore::getInstance()->resetLockDiscovery();
    }

    public function onStart($swooleServer)
    {
        $this->writePid($swooleServer->master_pid);
        sys_echo("server starting .....[$swooleServer->host:$swooleServer->port]");
    }

    public function onShutdown($swooleServer)
    {
        $this->removePidFile();
        sys_echo("server shutdown .....");
    }

    public function onWorkerStart($swooleServer, $workerId)
    {
        $_SERVER["WORKER_ID"] = $workerId;
        $this->bootWorkerStartItem($workerId);
        sys_echo("worker *$workerId starting .....");
    }

    public function onWorkerStop($swooleServer, $workerId)
    {
        // ServerDiscoveryInitiator::getInstance()->unlockDiscovery($workerId);
        sys_echo("worker *$workerId stopping .....");

        $num = Worker::getInstance()->reactionNum ?: 0;
        sys_echo("worker *$workerId still has $num requests in progress...");
    }

    public function onWorkerError($swooleServer, $workerId, $workerPid, $exitCode, $sigNo)
    {
        // ServerDiscoveryInitiator::getInstance()->unlockDiscovery($workerId);

        sys_echo("worker error happening [workerId=$workerId, workerPid=$workerPid, exitCode=$exitCode, signalNo=$sigNo]...");

        $num = Worker::getInstance()->reactionNum ?: 0;
        sys_echo("worker *$workerId still has $num requests in progress...");
    }

    public function onRequest(SwooleHttpRequest $httpRequest, SwooleHttpResponse $httpResponse)
    {
        if (method_exists($this->swooleServer, "stats")) {
            if ($httpRequest->server["request_uri"] === "/eW91emFuCg==/stats") {
                $httpResponse->write(json_encode($this->swooleServer->stats()));
                return;
            }
        }

        (new RequestHandler())->handle($httpRequest, $httpResponse);
    }
}
