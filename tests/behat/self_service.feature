@tool @tool_oauthmcp
Feature: Connected MCP apps self-service
  In order to stay in control of applications connected to my account
  As a Moodle user
  I need to see and revoke my MCP grants from my profile

  Background:
    Given the MCP server is enabled
    And the following "users" exist:
      | username | firstname | lastname | email              |
      | connor   | Connor    | User     | connor@example.com |
    And the following "permission overrides" exist:
      | capability            | permission | role | contextlevel | reference |
      | tool/oauthmcp:connect | Allow      | user | System       |           |
    And a public MCP OAuth client "Acme Connector" exists
    And the user "connor" has an MCP grant for client "Acme Connector"

  Scenario: A user sees a connected application and revokes it
    Given I log in as "connor"
    When I visit "/admin/tool/oauthmcp/userapps.php"
    Then I should see "Connected MCP apps"
    And I should see "Acme Connector"
    When I click on "Revoke access" "link"
    Then I should see "Access revoked"
    And I should not see "Acme Connector"

  Scenario: A user with no grants sees the empty state
    Given the following "users" exist:
      | username | firstname | lastname | email            |
      | empty    | Empty     | User     | empty@example.com |
    And I log in as "empty"
    When I visit "/admin/tool/oauthmcp/userapps.php"
    Then I should see "No applications are connected to your account"
