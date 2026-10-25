@local @local_brokenlinks
Feature: Check the links of a course
  In order to keep my course working
  As a teacher
  I need to find the broken links in it

  Background:
    Given the following "courses" exist:
      | fullname | shortname | summary                                              |
      | Course 1 | C1        | <a href="@@PLUGINFILE@@/handout.pdf">The handout</a> |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: A teacher checks the links of a course and marks one as not broken
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I navigate to "Link Scanner" in current page administration
    Then I should see "This course has not been checked yet."
    And I press "Check links"
    And I should see "The link check has been queued."
    And I run all adhoc tasks
    And I reload the page
    And I should see "The handout"
    And I should see "Embedded file is missing."
    And I press "Not broken"
    And I should see "The link is marked as not broken"
    And I should see "No broken links found."
    And I follow "Not broken (1)"
    And I should see "handout.pdf"
    And I press "Check again in scans"
    And I should see "No links are marked as not broken in this course."

  @javascript
  Scenario: The report says when a link check has finished
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Link Scanner" in current page administration
    When I press "Check links"
    Then I should see "A link check is waiting to start."
    And I run all adhoc tasks
    And I wait "12" seconds
    And I should see "The link check has finished."
    And I click on "See results" "link"
    And I should see "Embedded file is missing."
