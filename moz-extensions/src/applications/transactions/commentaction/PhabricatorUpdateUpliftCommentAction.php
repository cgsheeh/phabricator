<?php

class PhabricatorUpdateUpliftCommentAction
  extends PhabricatorEditEngineCommentAction {

  public function getPHUIXControlType() {
    return 'form';
  }

  public function getPHUIXControlSpecification() {
    $value = $this->getValue();
    $initial = false;

    if (empty($value) || $value == null) {
      $value = $this->getInitialValue();
      $initial = true;
    }

    return array(
        'id' => 'uplift-form',
        'initial' => $initial,
        'questions' => $value,
    );

  }

}
