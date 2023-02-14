/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `a`
--

DROP TABLE IF EXISTS `a`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE a (
id bigint
);
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `a`
--
-- WHERE:  id > 0 ORDER BY id ASC

LOCK TABLES `a` WRITE;
/*!40000 ALTER TABLE `a` DISABLE KEYS */;
INSERT INTO `a` VALUES (1);
/*!40000 ALTER TABLE `a` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `b`
--

DROP TABLE IF EXISTS `b`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE b (
id bigint,
v2 integer,
v3 json engine='rowwise',
v1 text
) engine='columnar';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `b`
--
-- WHERE:  id > 0 ORDER BY id ASC

LOCK TABLES `b` WRITE;
/*!40000 ALTER TABLE `b` DISABLE KEYS */;
INSERT INTO `b` VALUES (1,'63d767ed1c605',0,'{\"key\":672,\"value\":\"63d767ed1c60d\"}'),(2,'63d767ed1c618',569,'{\"key\":500,\"value\":\"63d767ed1c619\"}'),(3,'63d767ed1c61e',566,'{\"key\":292,\"value\":\"63d767ed1c61f\"}'),(4,'63d767ed1c623',1419,'{\"key\":200,\"value\":\"63d767ed1c624\"}'),(5,'63d767ed1c629',2388,'{\"key\":532,\"value\":\"63d767ed1c62a\"}'),(6,'63d767ed1c62e',665,'{\"key\":163,\"value\":\"63d767ed1c62f\"}'),(7,'63d767ed1c633',12,'{\"key\":395,\"value\":\"63d767ed1c634\"}'),(8,'63d767ed1c638',805,'{\"key\":722,\"value\":\"63d767ed1c639\"}'),(9,'63d767ed1c63e',4920,'{\"key\":527,\"value\":\"63d767ed1c63f\"}'),(10,'63d767ed1c644',8460,'{\"key\":304,\"value\":\"63d767ed1c645\"}'),(11,'63d767ed1c649',3560,'{\"key\":879,\"value\":\"63d767ed1c64a\"}'),(12,'63d767ed1c64e',352,'{\"key\":335,\"value\":\"63d767ed1c64f\"}'),(13,'63d767ed1c653',3552,'{\"key\":992,\"value\":\"63d767ed1c654\"}'),(14,'63d767ed1c658',2418,'{\"key\":247,\"value\":\"63d767ed1c659\"}'),(15,'63d767ed1c65d',10962,'{\"key\":419,\"value\":\"63d767ed1c65e\"}'),(16,'63d767ed1c662',705,'{\"key\":736,\"value\":\"63d767ed1c663\"}'),(17,'63d767ed1c667',4080,'{\"key\":231,\"value\":\"63d767ed1c668\"}'),(18,'63d767ed1c66c',5882,'{\"key\":245,\"value\":\"63d767ed1c66d\"}'),(19,'63d767ed1c671',11070,'{\"key\":705,\"value\":\"63d767ed1c672\"}'),(20,'63d767ed1c676',11913,'{\"key\":952,\"value\":\"63d767ed1c677\"}');
/*!40000 ALTER TABLE `b` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
