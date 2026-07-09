@tool @tool_oauthmcp
Feature: OAuth client administration
  In order to connect trusted applications without dynamic registration
  As an administrator
  I need to create, disable and delete OAuth clients

  Background:
    Given the MCP server is enabled
    And I log in as "admin"

  Scenario: An administrator registers a public client
    When I visit "/admin/tool/oauthmcp/clients.php"
    And I set the field "Client name" to "Manual Connector"
    And I set the field "Redirect URIs" to "https://client.example/callback"
    And I press "Save changes"
    Then I should see "Manual Connector"
    And I should see "Public"

  Scenario: An administrator disables and deletes a client
    Given a public MCP OAuth client "Acme Connector" exists
    When I visit "/admin/tool/oauthmcp/clients.php"
    Then I should see "Acme Connector"
    When I click on "Delete" "link"
    And I press "Continue"
    Then I should not see "Acme Connector"
