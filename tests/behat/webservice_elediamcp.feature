@webservice_elediamcp
Feature: MCP web service protocol is available
  In order to use the MCP web service
  As an admin
  I need the MCP protocol to be listed in the web service protocols

  @javascript
  Scenario: MCP protocol appears in web service protocol settings
    Given I log in as "admin"
    When I navigate to "Server > Web services > Manage protocols" in site administration
    Then I should see "Model Context Protocol"
