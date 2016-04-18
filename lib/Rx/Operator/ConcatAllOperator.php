<?php

namespace Rx\Operator;

use Rx\Disposable\CompositeDisposable;
use Rx\Disposable\EmptyDisposable;
use Rx\Disposable\SerialDisposable;
use Rx\Observable;
use Rx\ObservableInterface;
use Rx\Observer\CallbackObserver;
use Rx\ObserverInterface;
use Rx\SchedulerInterface;

class ConcatAllOperator implements OperatorInterface
{
    /** @var  array */
    private $buffer;

    /** @var CompositeDisposable */
    private $disposable;

    /** @var SerialDisposable */
    private $innerDisposable;

    /** @var bool */
    private $startBuffering;

    /** @var bool */
    private $sourceCompleted;

    /** @var bool */
    private $innerCompleted;

    public function __construct()
    {
        $this->buffer          = [];
        $this->disposable      = new CompositeDisposable();
        $this->innerDisposable = new EmptyDisposable();
        $this->startBuffering  = false;
        $this->sourceCompleted = false;
        $this->innerCompleted  = true;
    }

    /**
     * @param ObservableInterface $observable
     * @param ObserverInterface $observer
     * @param SchedulerInterface|null $scheduler
     * @return CompositeDisposable
     */
    public function __invoke(ObservableInterface $observable, ObserverInterface $observer, SchedulerInterface $scheduler = null)
    {
        $subscription = $observable->subscribe(new CallbackObserver(
            function (ObservableInterface $innerObservable) use ($observable, $observer, $scheduler) {
                try {

                    if ($this->startBuffering === true) {
                        $this->buffer[] = $innerObservable;
                        return;
                    }

                    $this->startBuffering = true;

                    $onCompleted = function () use (&$subscribeToInner, $observer) {

                        $this->disposable->remove($this->innerDisposable);
                        $this->innerDisposable->dispose();

                        $this->innerCompleted = true;

                        $obs = array_shift($this->buffer);

                        if ($obs) {
                            $subscribeToInner($obs);
                        } elseif ($this->sourceCompleted === true) {
                            $observer->onCompleted();
                        }

                        if (empty($this->buffer)) {
                            $this->startBuffering = false;
                        }
                    };

                    $subscribeToInner = function ($observable) use ($observer, $scheduler, &$onCompleted) {
                        $callbackObserver = new CallbackObserver(
                            [$observer, 'onNext'],
                            [$observer, 'onError'],
                            $onCompleted
                        );

                        $this->innerCompleted = false;

                        $this->innerDisposable = $observable->subscribe($callbackObserver, $scheduler);
                        $this->disposable->add($this->innerDisposable);
                    };

                    $subscribeToInner($innerObservable);

                } catch (\Exception $e) {
                    $observer->onError($e);
                }
            },
            [$observer, 'onError'],
            function () use ($observer) {
                $this->sourceCompleted = true;
                if ($this->innerCompleted === true) {
                    $observer->onCompleted();
                }
            }
        ), $scheduler);

        $this->disposable->add($subscription);

        return $this->disposable;
    }
}