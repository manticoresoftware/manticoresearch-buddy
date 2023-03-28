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
-- Current Database: `Manticore`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `Manticore`;

USE `Manticore`;

--
-- Table structure for table `a`
--

DROP TABLE IF EXISTS `a`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `a` (
`id` bigint
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
CREATE TABLE `b` (
`id` bigint,
`v1` text,
`v2` integer,
`v3` json engine='rowwise'
) engine='columnar';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `b`
--
-- WHERE:  id > 0 ORDER BY id ASC

LOCK TABLES `b` WRITE;
/*!40000 ALTER TABLE `b` DISABLE KEYS */;
INSERT INTO `b` VALUES (1,'641aa6975e296',0,'{\"key\":313,\"value\":\"641aa6975e29f\"}'),(2,'641aa6975e2b0',644,'{\"key\":619,\"value\":\"641aa6975e2b1\"}'),(3,'641aa6975e2b9',1706,'{\"key\":623,\"value\":\"641aa6975e2ba\"}'),(4,'641aa6975e2c3',1011,'{\"key\":325,\"value\":\"641aa6975e2c5\"}'),(5,'641aa6975e2cc',224,'{\"key\":327,\"value\":\"641aa6975e2ce\"}'),(6,'641aa6975e2d5',4170,'{\"key\":608,\"value\":\"641aa6975e2d6\"}'),(7,'641aa6975e2dd',1068,'{\"key\":694,\"value\":\"641aa6975e2de\"}'),(8,'641aa6975e2e5',1421,'{\"key\":0,\"value\":\"641aa6975e2e7\"}'),(9,'641aa6975e2ef',1232,'{\"key\":893,\"value\":\"641aa6975e2f1\"}'),(10,'641aa6975e2f8',7785,'{\"key\":218,\"value\":\"641aa6975e2f9\"}'),(11,'641aa6975e300',7340,'{\"key\":66,\"value\":\"641aa6975e302\"}'),(12,'641aa6975e309',10681,'{\"key\":739,\"value\":\"641aa6975e30b\"}'),(13,'641aa6975e312',5304,'{\"key\":282,\"value\":\"641aa6975e313\"}'),(14,'641aa6975e31a',351,'{\"key\":901,\"value\":\"641aa6975e31c\"}'),(15,'641aa6975e323',5418,'{\"key\":28,\"value\":\"641aa6975e324\"}'),(16,'641aa6975e32b',10800,'{\"key\":559,\"value\":\"641aa6975e32d\"}'),(17,'641aa6975e334',5936,'{\"key\":477,\"value\":\"641aa6975e336\"}'),(18,'641aa6975e33c',782,'{\"key\":366,\"value\":\"641aa6975e33e\"}'),(19,'641aa6975e345',36,'{\"key\":777,\"value\":\"641aa6975e347\"}'),(20,'641aa6975e34e',5548,'{\"key\":867,\"value\":\"641aa6975e350\"}');
/*!40000 ALTER TABLE `b` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `c`
--

DROP TABLE IF EXISTS `c`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `c` (
`id` bigint,
`v1` text,
`v2` integer engine='columnar',
`v3` json
);
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `c`
--
-- WHERE:  id > 0 ORDER BY id ASC

LOCK TABLES `c` WRITE;
/*!40000 ALTER TABLE `c` DISABLE KEYS */;
INSERT INTO `c` VALUES (1,'641aa6975e2aa',0,'{\"key\":238,\"value\":\"641aa6975e2ac\"}'),(2,'641aa6975e2b4',931,'{\"key\":701,\"value\":\"641aa6975e2b6\"}'),(3,'641aa6975e2bd',662,'{\"key\":449,\"value\":\"641aa6975e2bf\"}'),(4,'641aa6975e2c7',429,'{\"key\":645,\"value\":\"641aa6975e2c9\"}'),(5,'641aa6975e2d0',3844,'{\"key\":360,\"value\":\"641aa6975e2d2\"}'),(6,'641aa6975e2d9',4980,'{\"key\":267,\"value\":\"641aa6975e2da\"}'),(7,'641aa6975e2e1',3594,'{\"key\":816,\"value\":\"641aa6975e2e3\"}'),(8,'641aa6975e2e9',4879,'{\"key\":667,\"value\":\"641aa6975e2eb\"}'),(9,'641aa6975e2f3',2952,'{\"key\":870,\"value\":\"641aa6975e2f5\"}'),(10,'641aa6975e2fc',2106,'{\"key\":912,\"value\":\"641aa6975e2fe\"}'),(11,'641aa6975e305',4570,'{\"key\":548,\"value\":\"641aa6975e306\"}'),(12,'641aa6975e30d',9889,'{\"key\":917,\"value\":\"641aa6975e30f\"}'),(13,'641aa6975e316',2292,'{\"key\":763,\"value\":\"641aa6975e318\"}'),(14,'641aa6975e31e',1014,'{\"key\":303,\"value\":\"641aa6975e320\"}'),(15,'641aa6975e327',5222,'{\"key\":718,\"value\":\"641aa6975e328\"}'),(16,'641aa6975e32f',12870,'{\"key\":707,\"value\":\"641aa6975e331\"}'),(17,'641aa6975e338',5008,'{\"key\":109,\"value\":\"641aa6975e33a\"}'),(18,'641aa6975e341',14824,'{\"key\":180,\"value\":\"641aa6975e342\"}'),(19,'641aa6975e349',8388,'{\"key\":829,\"value\":\"641aa6975e34b\"}'),(20,'641aa6975e352',15523,'{\"key\":429,\"value\":\"641aa6975e354\"}');
/*!40000 ALTER TABLE `c` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
