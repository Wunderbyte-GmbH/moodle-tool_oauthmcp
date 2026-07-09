@tool @tool_oauthmcp
Feature: OAuth consent screen
  In order to control which applications may act in my name
  As a Moodle user
  I need to approve or deny access on the consent screen

  Background:
    Given the MCP server is enabled
    And the following "users" exist:
      | username | firstname | lastname | email              |
      | connor   | Connor    | User     | connor@example.com |
    And the following "roles" exist:
      | shortname | name        | archetype |
      | mcpuser   | MCP user    |           |
    And the following "permission overrides" exist:
      | capability            | permission | role    | contextlevel | reference |
      | tool/oauthmcp:connect | Allow      | mcpuser | System       |           |
    And the following "system role assigns" exist:
      | user   | role    |
      | connor | mcpuser |
    And a public MCP OAuth client "Acme Connector" exists

  @javascript
  Scenario: A permitted user sees the consent screen with client and scopes
    Given I log in as "connor"
    When I open the MCP authorization page for client "Acme Connector"
    Then I should see "Authorise application access"
    And I should see "Acme Connector"
    And I should see "Read information from the site in your name"
    And I should see "Change site data in your name"
    And "Allow access" "button" should exist
    And "Deny" "button" should exist

  @javascript
  Scenario: Denying access leaves the consent screen
    Given I log in as "connor"
    And I open the MCP authorization page for client "Acme Connector"
    When I press "Deny"
    Then I should not see "Authorise application access"

  Scenario: A user without the connect capability is refused
    Given the following "users" exist:
      | username | firstname | lastname | email           |
      | noaccess | No        | Access   | no@example.com  |
    And I log in as "noaccess"
    When I open the MCP authorization page for client "Acme Connector"
    Then I should see "not enabled for MCP access"
