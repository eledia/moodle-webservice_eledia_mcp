@webservice @webservice_elediamcp
Feature: MCP token self-service management
  In order to let MCP clients and AI agents act on my behalf
  As a Moodle user
  I need to create, view metadata for, and revoke my own MCP tokens

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | student1 | Erika     | Neu      | erika.neu@example.com |
    And the following "core_webservice > Service" exists:
      | name              | MCP behat service  |
      | shortname         | mcpbehat           |
      | enabled           | 1                  |
      | requiredcapability| webservice/elediamcp:use |
    And the MCP token service is "mcpbehat"

  Scenario: The MCP tokens link is available from the user preferences page
    Given I log in as "student1"
    When I visit "/user/preferences.php"
    Then I should see "MCP tokens"

  Scenario: The MCP shell help action opens the MCP handbook
    Given I log in as "admin"
    When I visit "/webservice/elediamcp/configuration.php"
    Then the MCP shell help action should target the MCP handbook
    When I visit "/webservice/elediamcp/help.php"
    Then I should see "Model Context Protocol help"
    And I should see "Free and premium tools"
    And "#block-region-side-pre" "css_element" should not exist
    And "#theme_boost-drawers-blocks" "css_element" should not exist

  Scenario: A user creates a token, sees the value once, and revokes it
    Given I log in as "student1"
    And I visit "/webservice/elediamcp/token/index.php"
    Then I should see "You have not created any MCP tokens yet."

    # Create a token.
    When I set the field "Label" to "My laptop"
    And I set the field "Service" to "MCP behat service"
    And I press "Create token"

    # The full value is revealed exactly once, with the new token listed as active.
    Then I should see "Copy it now"
    And I should see "My laptop"
    And I should see "Active"

    # Reloading must not reveal the value again.
    When I reload the page
    Then I should not see "Copy it now"
    And I should see "My laptop"

    # Revoke the token.
    When I click on "Revoke" "link" in the "My laptop" "table_row"
    Then I should see "Are you sure you want to revoke"
    When I press "Continue"
    Then I should see "The token has been revoked."
    And I should see "Revoked"

  Scenario: A user is told when they may not use any configured MCP service
    Given the following "core_webservice > Service" exists:
      | name               | MCP managers only       |
      | shortname          | mcpmgr                  |
      | enabled            | 1                       |
      | requiredcapability | webservice/elediamcp:viewcaps |
    And the MCP token service is "mcpmgr"
    And I log in as "student1"
    When I visit "/webservice/elediamcp/token/index.php"
    Then I should see "you are not currently permitted to use any of them"
