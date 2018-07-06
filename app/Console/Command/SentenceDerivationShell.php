<?php

class Walker {
    private $model;
    private $buffer = array();
    public $bufferSize = 1000;
    public $allowRewindSize = 20;

    public function __construct($model) {
        $this->model = $model;
    }

    private function setBufferPointerAt($i) {
        reset($this->buffer);
        while (key($this->buffer) !== $i) {
            next($this->buffer);
        }
    }

    public function next() {
        $next = next($this->buffer);
        if ($next === false) {
            if (empty($this->buffer)) {
                $lastId = 0;
            } else {
                $last = end($this->buffer);
                $lastId = $last[$this->model->alias][$this->model->primaryKey];
            }
            $fetchSize = $this->bufferSize - $this->allowRewindSize;
            $rows = $this->model->find('all', array(
                'conditions' => array('id > ' => $lastId),
                'limit' => $fetchSize,
            ));
            if (empty($rows)) {
                return false;
            }
            $remainder = array_slice($this->buffer, -$this->allowRewindSize, $this->allowRewindSize);
            $this->buffer = array_merge($remainder, $rows);
            $this->setBufferPointerAt(count($remainder));
            $next = current($this->buffer);
        }
        return $next;
    }
}

class SentenceDerivationShell extends AppShell {

    public $uses = array('Sentence', 'Contribution');

    public function main() {
        $proceeded = $this->setSentenceBasedOnId();
        $this->out("\n$proceeded sentences proceeded.\n");
    }

    public function setSentenceBasedOnId() {
        $walker = new Walker($this->Contribution);
        while ($log = $walker->next()) {
        }
    }
}
