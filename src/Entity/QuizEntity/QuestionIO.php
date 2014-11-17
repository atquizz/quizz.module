<?php

namespace Drupal\quiz\Entity\QuizEntity;

use Drupal\quiz\Entity\QuizEntity;
use Drupal\quiz_question\Entity\Question;
use PDO;

class QuestionIO {

  private $quiz;

  public function __construct(QuizEntity $quiz) {
    $this->quiz = $quiz;
  }

  /**
   * Builds the questionlist for quizzes with categorized random questions
   */
  public function buildCategoziedQuestionList() {
    if (!$question_types = array_keys(quiz_get_question_types())) {
      return array();
    }

    $questions = array();
    $nids = array();
    $total_count = 0;
    foreach ($this->quiz->getTermsByVid() as $term) {
      $query = db_select('node', 'n');
      $query->join('taxonomy_index', 'tn', 'n.nid = tn.nid');
      $query->fields('n', array('nid', 'vid'));
      $query->fields('tn', array('tid'));
      $query->condition('n.status', 1);
      $query->condition('n.type', $question_types);
      $query->condition('tn.tid', $term->tid);
      if (!empty($nids)) {
        $query->condition('n.nid', $nids, 'NOT IN');
      }
      $query->range(0, $term->number);
      $query->orderBy('RAND()');
      $result = $query->execute();
      $count = 0;
      while ($question = $result->fetchAssoc()) {
        $count++;
        $question['tid'] = $term->tid;
        $question['number'] = $count + $total_count;
        $questions[] = $question;
        $nids[] = $question['nid'];
      }
      $total_count += $count;
      if ($count < $term->number) {
        return array(); // Not enough questions
      }
    }
    return $questions;
  }

  /**
   * Retrieves a list of questions (to be taken) for a given quiz.
   *
   * If the quiz has random questions this function only returns a random
   * selection of those questions. This function should be used to decide
   * what questions a quiz taker should answer.
   *
   * This question list is stored in the user's result, and may be different
   * when called multiple times. It should only be used to generate the layout
   * for a quiz attempt and NOT used to do operations on the questions inside of
   * a quiz.
   *
   * @return array[] Array of question info.
   */
  public function getQuestionList() {
    if (QUESTION_CATEGORIZED_RANDOM == $this->quiz->randomization) {
      $questions = $this->buildCategoziedQuestionList();
    }
    return $questions = $this->getRequiredQuestions();
  }

  /**
   * @return array
   */
  private function getRequiredQuestions() {
    // Get required questions first.
    $query = db_query('
        SELECT
          relationship.question_nid as nid,
          relationship.question_vid as vid,
          question.type,
          relationship.qr_id,
          relationship.qr_pid,
          relationship.weight
        FROM {quiz_relationship} relationship
          JOIN {node} question ON relationship.question_nid = question.nid
          LEFT JOIN {quiz_relationship} sub_relationship ON (
              relationship.qr_pid = sub_relationship.qr_id
              OR (relationship.qr_pid IS NULL AND relationship.qr_id = sub_relationship.qr_id)
          )
        WHERE relationship.quiz_vid = :quiz_vid
          AND relationship.question_status = :question_status
          AND question.status = 1
        ORDER BY sub_relationship.weight, relationship.weight', array(
        ':quiz_vid'        => $this->quiz->vid,
        ':question_status' => QUESTION_ALWAYS
    ));

    // Just to make it easier on us, let's use a 1-based index.
    $i = 1;
    $questions = array();
    while ($question_node = $query->fetchAssoc()) {
      $questions[$i++] = $question_node;
    }

    // Get random questions for the remainder.
    if ($this->quiz->number_of_random_questions > 0) {
      $random_questions = $this->getRandomQuestions();
      $questions = array_merge($questions, $random_questions);

      // Unable to find enough requested random questions.
      if ($this->quiz->number_of_random_questions > count($random_questions)) {
        return array();
      }
    }

    // Shuffle questions if required.
    if ($this->quiz->randomization > 0) {
      shuffle($questions);
    }

    return $questions;
  }

  /**
   * Get an array list of random questions for a quiz.
   *
   * @return array[] Array of nid/vid combos for quiz questions.
   */
  private function getRandomQuestions() {
    $num_random = $this->quiz->number_of_random_questions;
    $term_id = $this->quiz->tid;
    $questions = array();
    if ($num_random > 0) {
      if ($term_id > 0) {
        $questions = $this->quiz->getQuestionIO()->getRandomTaxonomyQuestionIds($term_id, $num_random);
      }
      else {
        // Select random question from assigned pool.
        $result = db_query_range(
          "SELECT question_nid as nid, question_vid as vid, n.type
          FROM {quiz_relationship} qnr
          JOIN {node} n on qnr.question_nid = n.nid
          WHERE qnr.quiz_vid = :quiz_vid
          AND qnr.quiz_qid = :quiz_qid
          AND qnr.question_status = :question_status
          AND n.status = 1
          ORDER BY RAND()", 0, $this->quiz->number_of_random_questions, array(
            ':quiz_vid'        => $this->quiz->vid,
            ':quiz_qid'        => $this->quiz->qid,
            ':question_status' => QUESTION_RANDOM
          )
        );
        while ($question_node = $result->fetchAssoc()) {
          $question_node['random'] = TRUE;
          $question_node['relative_max_score'] = $this->quiz->max_score_for_random;
          $questions[] = $question_node;
        }
      }
    }
    return $questions;
  }

  /**
   * Get all of the question nid/vids by taxonomy term ID.
   *
   * @param int $term_id
   * @param int $amount
   *
   * @return
   *   Array of nid/vid combos, like array(array('nid'=>1, 'vid'=>2)).
   */
  public function getRandomTaxonomyQuestionIds($term_id, $amount) {
    if (!$term_id || !$term = taxonomy_term_load($term_id)) {
      return array();
    }

    // Flatten the taxonomy tree, and just keep term id's.
    $term_ids[] = $term->tid;
    if ($tree = taxonomy_get_tree($term->vid, $term->tid)) {
      foreach ($tree as $term) {
        $term_ids[] = $term->tid;
      }
    }

    // Get all published questions with one of the allowed term ids.
    $query = db_select('node', 'n');
    $query->innerJoin('taxonomy_index', 'tn', 'n.nid = tn.tid');
    $query->addExpression(1, 'random');
    return $query
        ->fields('n', array('nid', 'vid'))
        ->condition('n.status', 1)
        ->condition('tn.tid', $term_ids)
        ->condition('n.type', array_keys(quiz_get_question_types()))
        ->orderRandom()
        ->range(0, $amount)
        ->execute()->fetchAll(PDO::FETCH_ASSOC);
  }

}