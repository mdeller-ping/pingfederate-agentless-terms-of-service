# pingfederate-agentless-terms-of-service

![Overview Diagram](https://github.com/mdeller-ping/pingfederate-agentless-terms-of-service/blob/master/Overview%20Diagram.png)

## Overview

Simple Agentless Adapter for PingFederate that illustrates how to perform a Terms of Service acceptence during login.  Written in PHP.

## How to Use

* In PingFederate create Agentless Adapter (e.g., Terms of Service)
* Add Terms of Service adapter to Authentication Policy
* Use Policy Option to send entryUUID -> Terms of Service
* Place contents of dist/ onto web server with PHP enabled
* Edit index.php and provide your PingFederate, PingDirectory and other configuration
