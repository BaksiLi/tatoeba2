<?php

namespace App\Shell;

use App\Shell\AppShell;
use Cake\Utility\Hash;
use Cake\Datasource\Exception\RecordNotFoundException;

class Walker {
    private $model;
    private $startAtId;
    private $buffer = array();
    public $bufferSize = 1000;
    public $allowRewindSize = 20;

    public function __construct($model, $startAtId = 1) {
        $this->model = $model;
        $this->startAtId = $startAtId;
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
                $lastId = $this->startAtId - 1;
                $fetchSize = $this->bufferSize;
            } else {
                $last = end($this->buffer);
                $lastId = $last['id'];
                $fetchSize = $this->bufferSize - $this->allowRewindSize;
            }
            $rows = $this->model->find('all')
                ->where(['id > ' => $lastId])
                ->limit($fetchSize)
                ->toList();

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

    public function findAround($range, $matchFunction) {
        return array_merge(
            $this->findBefore($range, $matchFunction),
            $this->findAfter($range, $matchFunction)
        );
    }

    public function findAfter($range, $matchFunction) {
        $matches = array();
        $max = $range;
        for ($i = 0; $i < $max; $i++) {
           $row = $this->next($this->buffer);
           if ($row === false) {
              $range--;
           } else {
               if ($matchFunction($row)) {
                   $matches[] = $row;
               }
           }
        }
        if ($range != $max) {
           end($this->buffer);
        }
        for ($i = 0; $i < $range; $i++) {
           prev($this->buffer);
        }
        return $matches;
    }

    public function findBefore($range, $matchFunction) {
        $matches = array();
        $max = $range;
        for ($i = 0; $i < $max; $i++) {
           if (prev($this->buffer) === false) {
              $range--;
           }
        }
        if ($range != $max) {
           reset($this->buffer);
        }
        for ($i = 0; $i < $range; $i++) {
           $row = current($this->buffer);
           if ($matchFunction($row)) {
               $matches[] = $row;
           }
           $this->next($this->buffer);
        }
        return $matches;
    }
}

class SentenceDerivationShell extends AppShell {

    public $uses = array('Sentence', 'Contribution');
    public $batchSize = 1000;
    public $linkEraFirstId = 330930;
    public $linkABrange = array(890774, 909052);
    private $maxFindAroundRange = 87;

    public function main() {
        $proceeded = $this->run();
        $this->out("\n$proceeded sentences proceeded.");
    }

    private function findLinkedSentence($sentenceId, $matches) {
        if (count($matches) == 0) {
            return 0;
        } elseif (count($matches) == 1) {
            return null;
        } else {
            // pattern link B-A, link A-B
            $linkBA = $matches[0];
            $linkAB = $matches[1];
            if ($linkAB['id'] >= $this->linkABrange[0] && $linkAB['id'] <= $this->linkABrange[1]) {
                // pattern link A-B, link B-A
                $tmp = $linkBA;
                $linkBA = $linkAB;
                $linkAB = $tmp;
            }
            if ($sentenceId == $linkAB['sentence_id'] && $sentenceId == $linkBA['translation_id']) {
               return $linkAB['translation_id'];
            } else {
               return 0;
            }
        }
    }

    private function calcBasedOnId($walker, $log) {
        $matches = $walker->findAround($this->maxFindAroundRange, function ($elem) use ($log) {
            $isSameAuthor = $elem['user_id'] == $log['user_id'];
            $isInsertLink = $elem['action'] == 'insert' && $elem['type'] == 'link';
            $creatDate = strtotime($log['datetime']);
            $otherDate = strtotime($elem['datetime']);
            $closeDatetime = abs($otherDate - $creatDate) <= 310;

            $isRelated = ($elem['translation_id'] == $log['sentence_id'] && $elem['sentence_id'] < $log['sentence_id'])
                         || ($elem['sentence_id'] == $log['sentence_id'] && $elem['translation_id'] < $log['sentence_id']);
            return $isInsertLink && $isRelated && $closeDatetime && $isSameAuthor;
        });
        return $this->findLinkedSentence($log['sentence_id'], $matches);
    }

    private function saveDerivations($derivations) {
        $ids = Hash::extract($derivations, '{n}.id');
        if (!empty($ids)) {
            $oldData = $this->Sentences->find()
            ->where(['id IN' => $ids])
            ->toList();
            $entities = $this->Sentences->patchEntities($oldData, $derivations);
            if ($this->Sentences->saveMany($entities)) {
                $this->out('.', 0);
                return count($derivations);
            }
        }
         
        return 0;
    }

    public function findDuplicateCreationRecords() {
        $this->out("Finding duplicate creation records... ", 0);
        $result = $this->Contributions->find()
            ->select(['min' => 'MIN(id)', 'sentence_id'])
            ->where(['action' => 'insert', 'type' => 'sentence']) 
            ->group(['sentence_id'])
            ->having('count(sentence_id) > 1')
            ->toList();
        $result = Hash::combine($result, '{n}.sentence_id', '{n}.min');
        $this->out('done ('.count($result).' sentences affected)');
        return $result;
    }

    public function setSentenceBasedOnId($creationDups) {
        $total = 0;
        $derivations = array();
        $saveExtraOptions = array(
            'modified' => false,
            'callbacks' => false
        );
        $this->out("Setting 'based_on_id' field for all sentences", 0);
        $walker = new Walker($this->Contributions, $this->linkEraFirstId);
        $walker->allowRewindSize = $this->maxFindAroundRange;
        while ($log = $walker->next()) {
            if ($log['action']   == 'insert' &&
                $log['type']     == 'sentence' &&
                $log['datetime'] != '0000-00-00 00:00:00' && !empty($log['datetime']))
            {
                $sentenceId = $log['sentence_id'];
                try {
                    $sentence = $this->Sentences->get($sentenceId, ['fields' => ['based_on_id']]);
                    if (!is_null($sentence['based_on_id']) ||
                        (isset($creationDups[$sentenceId]) && $creationDups[$sentenceId] != $log['id'])
                    ) {
                        continue;
                    }
                } catch (RecordNotFoundException $e) {
                    continue;
                }
                $basedOnId = $this->calcBasedOnId($walker, $log);
                if (!is_null($basedOnId)) {
                    $update = array('id' => $sentenceId, 'based_on_id' => $basedOnId);
                    $derivations[$sentenceId] = array_merge($update, $saveExtraOptions);
                }
                if (count($derivations) >= $this->batchSize) {
                    $total += $this->saveDerivations($derivations);
                    $derivations = array();
                }
            }
        }
        $total += $this->saveDerivations($derivations);
        return $total;
    }

    public function run() {
        $this->loadModel('Contributions');
        $this->loadModel('Sentences');
        $creationDups = $this->findDuplicateCreationRecords();
        $total = $this->setSentenceBasedOnId($creationDups);
        return $total;
    }
}
