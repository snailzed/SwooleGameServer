<?php

namespace interfaces\event;

interface SwooleEvent
{
    const ON_WORKER_STOP = 'workerStop';
    const ON_SHUT_DOWN = 'shutDown';
    const ON_HAND_SHAKE = 'handshake';
    const ON_OPEN = 'open';
    const ON_CLOSE = 'close';
}