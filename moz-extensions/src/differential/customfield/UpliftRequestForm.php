<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
* Represents the uplift request form.
*/
final class UpliftRequestForm {

    const UPLIFT_REQUEST_QUESTIONS = array(
        "User impact if declined" => "",
        "Code covered by automated testing" => false,
        "Fix verified in Nightly" => false,
        "Needs manual QE test" => false,
        "Steps to reproduce for manual QE testing" => "",
        "Risk associated with taking this patch" => "",
        "Explanation of risk level" => "",
        "String changes made/needed" => "",
        "Is Android affected?" => false,
    );

    // How each field is formatted in ReMarkup:
    // a bullet point with text in bold.
    const QUESTION_FORMATTING = "- **%s** %s";


    // Convert `bool` types to readable text, or return base text.
    private function valueAsAnswer($value): string {
        if ($value === true) {
            return "yes";
        } else if ($value === false) {
            return "no";
        } else {
            return $value;
        }
    }

    public function getRemarkup(): string {
        $questions = array();

        $value = $this->getValue();
        foreach ($value as $question => $answer) {
            $answer_readable = $this->valueAsAnswer($answer);
            $questions[] = sprintf(
                self::QUESTION_FORMATTING, $question, $answer_readable
            );
        }

        return implode("\n", $questions);
    }

    public function validateUpliftForm(): array {
        $validation_errors = array();

        # Allow clearing the form.
        if (empty($form)) {
            return $validation_errors;
        }

        $valid_questions = array_keys(self::UPLIFT_REQUEST_QUESTIONS);

        $validated_question = array();
        foreach($form as $question => $answer) {
            # Assert the question is valid.
            if (!in_array($question, $valid_questions)) {
                $validation_errors[] = "Invalid question: '$question'";
                continue;
            }

            $default_type = gettype(self::UPLIFT_REQUEST_QUESTIONS[$question]);

            # Assert the value is not empty.
            $empty_string = $default_type == "string" && empty($answer);
            $null_bool = $default_type == "boolean" && is_null($answer);
            if ($empty_string || $null_bool) {
                $validation_errors[] = "Need to answer '$question'";
                continue;
            }

            # Assert the type from the response matches the type of the default.
            $answer_type = gettype($answer);
            if ($default_type != $answer_type) {
                $validation_errors[] = "Parsing error: type '$answer_type' for '$question' doesn't match expected '$default_type'!";
                continue;
            }

            $validated_question[] = $question;
        }

        # Make sure we have all the required fields present in the response.
        $missing = array_diff($valid_questions, $validated_question);
        if (empty($validation_errors) && $missing) {
            foreach($missing as $missing_question) {
                $validation_errors[] = "Missing response for $missing_question";
            }
        }

        return $validation_errors;
    }

    // When storing the value convert the question => answer mapping to a JSON string.
    public function getValueForStorage(): string {
        return phutil_json_encode($this->getValue());
    }

    public function setValueFromStorage($value) {
        try {
            $this->setValue(phutil_json_decode($value));
        } catch (PhutilJSONParserException $ex) {
            $this->setValue(array());
        }
        return $this;
    }

    public function setValueFromApplicationTransactions($value) {
        $this->setValue($value);
        return $this;
    }

    public function setValue($value) {
        if (is_array($value)) {
            parent::setValue($value);
            return;
        }

        try {
            parent::setValue(phutil_json_decode($value));
        } catch (Exception $e) {
            parent::setValue(array());
        }
    }

    public function readFieldValueFromConduit(string $value) {
        return $value;
    }
}

